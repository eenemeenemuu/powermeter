<?php

/**
 * PowerMeter REST API Router
 *
 * Routes /api/* requests to the appropriate handler.
 */

// Determine project root (one level up from api/)
$projectRoot = dirname(__DIR__);

// Load autoloader for all src/ classes
require_once $projectRoot . '/src/autoload.php';

// Initialize config
$configFile = $projectRoot . '/config.inc.php';
$config = Config::load(file_exists($configFile) ? $configFile : null);

// Initialize data store
$dataStore = createDataStore($config);

// Parse the request URI
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = dirname($_SERVER['SCRIPT_NAME']); // e.g., /api
$path = substr(parse_url($requestUri, PHP_URL_PATH), strlen($basePath));
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Set JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Simple path-based router
try {
    if ($path === '/config' && $method === 'GET') {
        echo json_encode($config->toPublicArray(), JSON_PRETTY_PRINT);

    } elseif ($path === '/current' && $method === 'GET') {
        handleCurrent($config, $dataStore);

    } elseif ($path === '/log' && $method === 'POST') {
        handleLog($config, $dataStore);

    } elseif (preg_match('#^/chart-data/(\d{4}-\d{2}-\d{2})$#', $path, $matches) && $method === 'GET') {
        handleChartData($config, $dataStore, $matches[1]);

    } elseif (preg_match('#^/stats/month/(\d{4}-\d{2})$#', $path, $matches) && $method === 'GET') {
        handleStatsMonth($config, $dataStore, $matches[1]);

    } elseif (preg_match('#^/stats/year/(\d{4})$#', $path, $matches) && $method === 'GET') {
        handleStatsYear($config, $dataStore, $matches[1]);

    } elseif ($path === '/overview' && $method === 'GET') {
        handleOverview($config, $dataStore);

    } elseif ($path === '/files' && $method === 'GET') {
        handleFiles($config, $dataStore);

    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found', 'path' => $path]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// --- Handler Functions ---

function handleCurrent(Config $config, DataStoreInterface $dataStore): void
{
    // Multi-device mode: return all devices + combined power
    if ($config->isMultiDevice()) {
        $manager = new DeviceManager($config);
        $allResults = $manager->queryAllAsync();

        $devices = [];
        foreach ($allResults as $id => $stats) {
            if (isset($stats[0]) && $stats[0] === 'error') {
                $devices[$id] = ['error' => strip_tags($stats[1])];
            } else {
                $devices[$id] = [
                    'date' => $stats['date'],
                    'time' => $stats['time'],
                    'power' => floatval($stats['power']),
                    'temp' => isset($stats['temp']) && $stats['temp'] !== '' ? floatval($stats['temp']) : null,
                    'emeters' => isset($stats['emeters']) ? array_map('floatval', $stats['emeters']) : null,
                ];
            }
        }

        echo json_encode([
            'devices' => $devices,
            'combined_power' => DeviceManager::combinedPower($allResults),
            'source' => 'live',
        ], JSON_PRETTY_PRINT);
        return;
    }

    // Single-device mode: original behavior
    if ($config->use_cache && !isset($_GET['nocache'])) {
        $cached = $dataStore->getLatestStats();
        if ($cached) {
            $parts = explode(',', $cached);
            echo json_encode([
                'date' => $parts[0] ?? null,
                'time' => $parts[1] ?? null,
                'power' => isset($parts[2]) ? floatval($parts[2]) : null,
                'temp' => isset($parts[3]) && $parts[3] !== '' ? floatval($parts[3]) : null,
                'emeters' => array_slice(array_map('floatval', $parts), 4) ?: null,
                'source' => 'cache',
            ], JSON_PRETTY_PRINT);
            return;
        }
    }

    $driver = DriverFactory::createFromConfig($config);
    $stats = $driver->getStats();

    if (isset($stats[0]) && $stats[0] === 'error') {
        http_response_code(502);
        echo json_encode(['error' => strip_tags($stats[1])]);
        return;
    }

    echo json_encode([
        'date' => $stats['date'],
        'time' => $stats['time'],
        'power' => floatval($stats['power']),
        'temp' => isset($stats['temp']) && $stats['temp'] !== '' ? floatval($stats['temp']) : null,
        'emeters' => isset($stats['emeters']) ? array_map('floatval', $stats['emeters']) : null,
        'source' => 'live',
    ], JSON_PRETTY_PRINT);
}

function handleLog(Config $config, DataStoreInterface $dataStore): void
{
    $key = isset($_POST['key']) ? $_POST['key'] : '';
    if ($key !== $config->host_auth_key) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid auth key']);
        return;
    }

    $statsString = isset($_POST['stats']) ? $_POST['stats'] : '';
    if (!$statsString) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing stats parameter']);
        return;
    }

    // Validate format
    $regexCheck = ['[0-9]{2}\.[0-9]{2}\.[0-9]{4}', '[0-9]{2}:[0-9]{2}:[0-9]{2}'];
    for ($i = 0; $i < 14; $i++) {
        $regexCheck[] = '[\-0-9]{1,6}(\.[0-9]{1,3})?';
    }

    foreach (explode(',', $statsString) as $stat) {
        $check = array_shift($regexCheck);
        if ($stat && $check && !preg_match('/^' . $check . '$/', $stat)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid stats format']);
            return;
        }
    }

    // Check for duplicates
    $existing = $dataStore->getLatestStats();
    if ($existing && $existing == $statsString) {
        echo json_encode(['status' => 'duplicate', 'message' => 'Reading already recorded']);
        return;
    }

    $date = Helpers::dateDot2Dash(substr($statsString, 0, 10));
    $dataStore->appendReading($date, $statsString);

    $isBuffer = isset($_POST['buffer']) && $_POST['buffer'] == '1';
    if (!$isBuffer) {
        $dataStore->setLatestStats($statsString);
    }

    echo json_encode(['status' => 'ok']);
}

function handleChartData(Config $config, DataStoreInterface $dataStore, string $date): void
{
    $res = isset($_GET['res']) ? (int) $_GET['res'] : $config->res;
    $t1 = isset($_GET['t1']) ? (int) $_GET['t1'] : 0;
    $t2 = isset($_GET['t2']) ? (int) $_GET['t2'] : 23;
    $threePhase = isset($_GET['3p']) && $_GET['3p'];

    if ($t1 > $t2) {
        $t1 = $t2;
    }

    $lines = $dataStore->getReadings($date);

    if (empty($lines)) {
        http_response_code(404);
        echo json_encode(['error' => 'No data for date: ' . $date]);
        return;
    }

    $calc = new StatsCalculator($config);
    $result = $calc->processDay($lines, $res, $t1, $t2, $threePhase);

    echo json_encode([
        'date' => $date,
        'resolution' => $res,
        'time_range' => ['from' => $t1, 'to' => $t2],
        'three_phase' => $threePhase,
        'stats' => $result['stats'],
        'chart' => [
            'data_points' => $result['data_points'],
            'data_points_t' => $result['data_points_t'],
            'data_points_wh' => $result['data_points_wh'],
            'data_points_wh_feed' => $result['data_points_wh_feed'],
            'data_points_feed' => $result['data_points_feed'],
            'data_points_l1' => $result['data_points_l1'],
            'data_points_l2' => $result['data_points_l2'],
            'data_points_l3' => $result['data_points_l3'],
            'data_points_l4' => $result['data_points_l4'],
        ],
        'y_max' => $result['y_max'],
        'unit2_measured' => $result['unit2_measured'],
        'feed_measured' => $result['feed_measured'],
        'extra_data' => $result['extra_data'],
        'reading_count' => $result['reading_count'],
    ], JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
}

function handleStatsMonth(Config $config, DataStoreInterface $dataStore, string $month): void
{
    list($chartStats) = $dataStore->getChartStats();
    $feed = isset($_GET['feed']);

    $calc = new StatsCalculator($config);
    $result = $calc->processMonth($chartStats, $month, $feed);

    $unitLabel = $feed ? $config->unit1_label_out : $config->unit1_label;
    if (!$feed && $result['feed_measured']) {
        $unitLabel = $config->unit1_label_in;
    }

    echo json_encode([
        'month' => $month,
        'label' => $unitLabel,
        'unit' => $config->unit1,
        'feed' => $feed,
        'total_kwh' => $result['total_kwh'],
        'max' => $result['max'],
        'min' => $result['min'],
        'feed_measured' => $result['feed_measured'],
        'data' => $result['data'],
        'months' => $result['months'],
        'position' => $result['position'],
    ], JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
}

function handleStatsYear(Config $config, DataStoreInterface $dataStore, string $year): void
{
    list($chartStats) = $dataStore->getChartStats();
    $feed = isset($_GET['feed']);

    $calc = new StatsCalculator($config);
    $result = $calc->processYear($chartStats, $year, $feed);

    $unitLabel = $feed ? $config->unit1_label_out : $config->unit1_label;
    if (!$feed && $result['feed_measured']) {
        $unitLabel = $config->unit1_label_in;
    }

    echo json_encode([
        'year' => $year,
        'label' => $unitLabel,
        'unit' => $config->unit1,
        'feed' => $feed,
        'total_kwh' => $result['total_kwh'],
        'max' => $result['max'],
        'min' => $result['min'],
        'feed_measured' => $result['feed_measured'],
        'data' => $result['data'],
        'years' => $result['years'],
        'position' => $result['position'],
    ], JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
}

function handleOverview(Config $config, DataStoreInterface $dataStore): void
{
    list($files, , $fileDates) = $dataStore->getAvailableDates();
    list($chartStats, $chartStatsMonth, $chartStatsMonthFeed) = $dataStore->getChartStats();

    krsort($chartStatsMonth);
    krsort($chartStatsMonthFeed);

    $feedMeasured = false;
    foreach ($chartStats as $data) {
        if (isset($data[6])) {
            $feedMeasured = true;
            break;
        }
    }

    // Build daily stats
    $dailyStats = [];
    $allDates = array_unique(array_merge($fileDates, array_keys($chartStats)));
    rsort($allDates);

    foreach ($allDates as $date) {
        $entry = [
            'date' => $date,
            'has_file' => in_array($date, $fileDates),
            'wh' => isset($chartStats[$date][1]) ? floatval($chartStats[$date][1]) : null,
            'time_first' => isset($chartStats[$date][2]) ? $chartStats[$date][2] : null,
            'time_last' => isset($chartStats[$date][3]) ? $chartStats[$date][3] : null,
            'peak' => isset($chartStats[$date][4]) ? floatval($chartStats[$date][4]) : null,
            'time_peak' => isset($chartStats[$date][5]) ? $chartStats[$date][5] : null,
        ];
        if ($feedMeasured) {
            $entry['wh_feed'] = isset($chartStats[$date][6]) ? floatval($chartStats[$date][6]) : (isset($chartStats[$date]) ? 0 : null);
        }
        if ($config->unit2 == '%') {
            $entry['percent_min'] = isset($chartStats[$date][7]) ? floatval($chartStats[$date][7]) : null;
            $entry['percent_max'] = isset($chartStats[$date][8]) ? floatval($chartStats[$date][8]) : null;
        }
        $dailyStats[] = $entry;
    }

    // Build monthly overview (convert to kWh)
    $monthlyOverview = [];
    foreach ($chartStatsMonth as $year => $months) {
        $yearData = [];
        $yearSum = 0;
        foreach ($months as $month => $value) {
            $yearData[$month] = round($value / 1000, 2);
            $yearSum += $value;
        }
        $yearData['total'] = round($yearSum / 1000, 2);
        $monthlyOverview[$year] = $yearData;
    }

    $monthlyFeedOverview = [];
    if ($feedMeasured) {
        foreach ($chartStatsMonthFeed as $year => $months) {
            $yearData = [];
            $yearSum = 0;
            foreach ($months as $month => $value) {
                $yearData[$month] = round($value / 1000, 2);
                $yearSum += $value;
            }
            $yearData['total'] = round($yearSum / 1000, 2);
            $monthlyFeedOverview[$year] = $yearData;
        }
    }

    // Power details
    $powerDetails = null;
    if ($config->power_details_resolution) {
        $details = $dataStore->getChartDetails($config->power_details_resolution);
        if ($details) {
            $powerDetails = [];
            foreach ($details as $date => $wh) {
                list($deltas, $cumulative) = Helpers::calculatePowerDetails($wh);
                $powerDetails[$date] = [
                    'wh' => $wh,
                    'deltas' => $deltas,
                    'cumulative' => $cumulative,
                ];
            }
        }
    }

    echo json_encode([
        'feed_measured' => $feedMeasured,
        'unit1' => $config->unit1,
        'unit1_label' => $config->unit1_label,
        'unit1_label_in' => $config->unit1_label_in,
        'unit1_label_out' => $config->unit1_label_out,
        'monthly' => $monthlyOverview,
        'monthly_feed' => $monthlyFeedOverview,
        'daily' => $dailyStats,
        'power_details' => $powerDetails,
        'power_details_resolution' => $config->power_details_resolution,
    ], JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
}

function handleFiles(Config $config, DataStoreInterface $dataStore): void
{
    list($files, , $fileDates) = $dataStore->getAvailableDates();

    echo json_encode([
        'files' => $files,
        'dates' => array_values($fileDates),
        'count' => count($files),
    ], JSON_PRETTY_PRINT);
}

//EOF
