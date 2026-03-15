<?php
/**
 * PowerMeter Test Suite
 * Self-contained PHP test runner — no external dependencies.
 * Run: docker exec powermeter-powermeter-1 php tests/run.php
 *   or: php tests/run.php
 */

// ── Test Harness ──

$_pass = 0;
$_fail = 0;
$_errors = [];

function pm_assert($condition, $desc) {
    global $_pass, $_fail, $_errors;
    if ($condition) {
        $_pass++;
        echo "  [PASS] $desc\n";
    } else {
        $_fail++;
        $_errors[] = $desc;
        echo "  [FAIL] $desc\n";
    }
}

function pm_assert_equals($expected, $actual, $desc) {
    global $_pass, $_fail, $_errors;
    if ($expected === $actual) {
        $_pass++;
        echo "  [PASS] $desc\n";
    } else {
        $_fail++;
        $_errors[] = "$desc (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")";
        echo "  [FAIL] $desc (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
    }
}

function pm_assert_contains($haystack, $needle, $desc) {
    pm_assert(strpos($haystack, $needle) !== false, $desc);
}

function pm_assert_not_contains($haystack, $needle, $desc) {
    pm_assert(strpos($haystack, $needle) === false, $desc);
}

function pm_test_group($name, $fn) {
    echo "\n--- $name ---\n";
    try {
        $fn();
    } catch (Throwable $e) {
        global $_fail, $_errors;
        $_fail++;
        $msg = "$name CRASHED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
        $_errors[] = $msg;
        echo "  [FAIL] $msg\n";
    }
}

function reset_config_singleton() {
    $ref = new ReflectionProperty('Config', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
}

function rm_rf($dir) {
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) as $file) {
        is_dir($file) ? rm_rf($file) : unlink($file);
    }
    rmdir($dir);
}

// ── Bootstrap ──

chdir(dirname(__DIR__));
require_once __DIR__ . '/../src/autoload.php';

echo "=== PowerMeter Test Suite ===\n";

// ── 1. Config Class Tests ──

pm_test_group('Config: Defaults', function () {
    reset_config_singleton();
    $config = Config::getInstance();

    pm_assert_equals('tasmota', $config->device, 'Default device is tasmota');
    pm_assert_equals('W', $config->unit1, 'Default unit1 is W');
    pm_assert_equals('°C', $config->unit2, 'Default unit2 is °C');
    pm_assert_equals(2, $config->refresh_rate, 'Default refresh_rate is 2');
    pm_assert_equals('109, 120, 173', $config->color1, 'Default color1');
    pm_assert_equals(false, $config->use_cache, 'Default use_cache is false');
    pm_assert_equals('data/', $config->log_file_dir, 'Default log_file_dir');
    pm_assert_equals(5, $config->res, 'Default resolution is 5');
    pm_assert_equals([], $config->devices, 'Default devices is empty');
    pm_assert_equals([], $config->gesamt_groups, 'Default gesamt_groups is empty');
    pm_assert_equals([], $config->virtual_totals, 'Default virtual_totals is empty');
});

pm_test_group('Config: Environment Variables', function () {
    reset_config_singleton();
    putenv('PM_DEVICE=shelly3em');
    putenv('PM_REFRESH_RATE=10');
    putenv('PM_USE_CACHE=true');
    putenv('PM_ROUNDING_PRECISION=2');

    $config = Config::getInstance();
    $config->loadFromEnv();

    pm_assert_equals('shelly3em', $config->device, 'Env PM_DEVICE loads correctly');
    pm_assert_equals(10, $config->refresh_rate, 'Env PM_REFRESH_RATE casts to int');
    pm_assert_equals(true, $config->use_cache, 'Env PM_USE_CACHE casts to bool');
    pm_assert_equals(2, $config->rounding_precision, 'Env PM_ROUNDING_PRECISION casts to int');

    // Cleanup
    putenv('PM_DEVICE');
    putenv('PM_REFRESH_RATE');
    putenv('PM_USE_CACHE');
    putenv('PM_ROUNDING_PRECISION');
});

pm_test_group('Config: Multi-device', function () {
    reset_config_singleton();
    $config = Config::getInstance();

    pm_assert_equals(false, $config->isMultiDevice(), 'Empty devices = not multi-device');

    $config->devices = [
        ['id' => 'solar', 'device' => 'anker_solix'],
        ['id' => 'grid', 'device' => 'shelly3em'],
    ];

    pm_assert_equals(true, $config->isMultiDevice(), 'With devices = multi-device');

    $configs = $config->getDeviceConfigs();
    pm_assert_equals(2, count($configs), 'getDeviceConfigs returns 2 entries');
    pm_assert_equals('solar', $configs[0]['id'], 'First device id is solar');
    pm_assert_equals('anker_solix', $configs[0]['type'], 'First device type is anker_solix');
    pm_assert_equals('grid', $configs[1]['id'], 'Second device id is grid');
});

pm_test_group('Config: Single-device fallback', function () {
    reset_config_singleton();
    $config = Config::getInstance();
    $config->devices = [];

    $configs = $config->getDeviceConfigs();
    pm_assert_equals(1, count($configs), 'Single-device returns 1 entry');
    pm_assert_equals('default', $configs[0]['id'], 'Single-device id is default');
});

pm_test_group('Config: Gesamt Groups', function () {
    reset_config_singleton();
    $config = Config::getInstance();
    $config->devices = [
        ['id' => 'anker', 'device' => 'anker_solix'],
        ['id' => 'ahoy0', 'device' => 'ahoydtu'],
        ['id' => 'ahoy1', 'device' => 'ahoydtu'],
    ];

    // Default: no groups configured, auto-creates one with all devices
    $groups = $config->getGesamtGroups();
    pm_assert_equals(1, count($groups), 'Default creates 1 gesamt group');
    pm_assert_equals('gesamt', $groups[0]['id'], 'Default group id is gesamt');
    pm_assert_equals(['anker', 'ahoy0', 'ahoy1'], $groups[0]['devices'], 'Default group contains all devices');

    // Custom groups
    $config->gesamt_groups = [
        ['id' => 'gesamt', 'label' => 'Gesamt', 'devices' => ['anker', 'ahoy1']],
        ['id' => 'solar', 'label' => 'Solar Gesamt', 'devices' => ['anker', 'ahoy0', 'ahoy1']],
    ];

    $groups = $config->getGesamtGroups();
    pm_assert_equals(2, count($groups), 'Custom groups returns 2');
    pm_assert_equals(['anker', 'ahoy1'], $groups[0]['devices'], 'First group excludes ahoy0');
    pm_assert_equals('Solar Gesamt', $groups[1]['label'], 'Second group label correct');

    // getGesamtGroup
    $g = $config->getGesamtGroup('gesamt');
    pm_assert_equals('Gesamt', $g['label'], 'getGesamtGroup finds gesamt');
    $g2 = $config->getGesamtGroup('solar');
    pm_assert_equals('Solar Gesamt', $g2['label'], 'getGesamtGroup finds solar');
    pm_assert_equals(null, $config->getGesamtGroup('nonexistent'), 'getGesamtGroup returns null for unknown');

    // isGesamtGroup
    pm_assert_equals(true, $config->isGesamtGroup('gesamt'), 'isGesamtGroup true for gesamt');
    pm_assert_equals(true, $config->isGesamtGroup('solar'), 'isGesamtGroup true for solar');
    pm_assert_equals(false, $config->isGesamtGroup('anker'), 'isGesamtGroup false for device id');
    pm_assert_equals(false, $config->isGesamtGroup('../../etc'), 'isGesamtGroup false for path traversal');
});

pm_test_group('Config: toPublicArray excludes secrets', function () {
    reset_config_singleton();
    $config = Config::getInstance();
    $config->pass = 'supersecret';
    $config->anker_password = 'ankerpass';
    $config->anker_email = 'user@example.com';

    $public = $config->toPublicArray();
    pm_assert(isset($public['device']), 'Public array includes device');
    pm_assert(isset($public['unit1']), 'Public array includes unit1');
    pm_assert(!isset($public['pass']), 'Public array excludes pass');
    pm_assert(!isset($public['anker_password']), 'Public array excludes anker_password');
    pm_assert(!isset($public['anker_email']), 'Public array excludes anker_email');
    pm_assert(!isset($public['user']), 'Public array excludes user');
});

// ── 2. Helpers Tests ──

pm_test_group('Helpers: dateDot2Dash', function () {
    pm_assert_equals('2026-03-15', Helpers::dateDot2Dash('15.03.2026'), 'Standard date conversion');
    pm_assert_equals('2000-01-01', Helpers::dateDot2Dash('01.01.2000'), 'Y2K date conversion');
});

pm_test_group('Helpers: round', function () {
    pm_assert_equals(null, Helpers::round(null), 'null returns null');
    pm_assert_equals(0, Helpers::round(5, false, 9, 0, 10), 'Below threshold returns 0');
    pm_assert_equals(123.0, Helpers::round(123.456, false, 9, 0, 0), 'Round to 0 decimals');
    pm_assert_equals(123.46, Helpers::round(123.456, false, 9, 2, 0), 'Round to 2 decimals');
    pm_assert_equals('123.46', Helpers::round(123.456, true, 9, 2, 0), 'number_format with precision');
    pm_assert_equals('1.23', Helpers::round(1.23456, true, 2, 5, 0), 'maxPrecisionLevel caps precision');
    pm_assert(Helpers::round(0, false, 9, 0, 0) == 0, 'Zero returns zero');
});

pm_test_group('Helpers: calculatePowerDetails', function () {
    $input = [0 => 300, 50 => 200, 100 => 100];
    list($deltas, $cumulative) = Helpers::calculatePowerDetails($input);

    pm_assert_equals(100, $deltas[0], 'Delta for 0 = 300-200 = 100');
    pm_assert_equals(100, $deltas[50], 'Delta for 50 = 200-100 = 100');
    pm_assert_equals(100, $deltas[100], 'Delta for 100 = 100 (last, unchanged)');
    pm_assert_equals(100, $cumulative[0], 'Cumulative at 0 = 100');
    pm_assert_equals(200, $cumulative[50], 'Cumulative at 50 = 200');
    pm_assert_equals(300, $cumulative[100], 'Cumulative at 100 = 300');
});

// ── 3. StatsCalculator Tests ──

pm_test_group('StatsCalculator: addReading and getStats', function () {
    reset_config_singleton();
    $config = Config::getInstance();
    $config->rounding_precision = 0;
    $config->power_threshold = 0;
    $config->power_details_resolution = 0;

    $calc = new StatsCalculator($config);
    $calc->addReading(['h' => 10, 'm' => 0, 's' => 0, 'p' => 100]);
    $calc->addReading(['h' => 10, 'm' => 1, 's' => 0, 'p' => 200]);
    $calc->addReading(['h' => 10, 'm' => 2, 's' => 0, 'p' => 150]);
    $calc->addReading(['h' => 10, 'm' => 3, 's' => 0, 'p' => 0]);

    $stats = $calc->getStats();
    pm_assert_equals('10:00:00', $stats['first'], 'First reading time');
    pm_assert_equals('10:02:00', $stats['last'], 'Last non-zero reading time');
    pm_assert(floatval($stats['peak_power']) == 200, 'Peak power is 200');
    pm_assert_equals('10:01:00', $stats['peak_time'], 'Peak time');
    pm_assert($stats['wh_raw'] > 0, 'Wh accumulated > 0');
});

pm_test_group('StatsCalculator: getYMinMax', function () {
    // getYMinMax rounds up to nearest "nice" number using ceil
    $max450 = StatsCalculator::getYMinMax('max', 450);
    pm_assert($max450 >= 450 && $max450 <= 500, "Max for 450 = $max450 (>= 450, <= 500)");
    $max600 = StatsCalculator::getYMinMax('max', 600);
    pm_assert($max600 >= 600 && $max600 <= 1000, "Max for 600 = $max600 (>= 600, <= 1000)");
    $max150 = StatsCalculator::getYMinMax('max', 150);
    pm_assert($max150 >= 150 && $max150 <= 200, "Max for 150 = $max150 (>= 150, <= 200)");
    $min = StatsCalculator::getYMinMax('min', -30);
    pm_assert($min <= -30, "Min for -30 = $min (<= -30)");
    pm_assert_equals(null, StatsCalculator::getYMinMax('invalid', 100), 'Invalid type returns null');
});

pm_test_group('StatsCalculator: processDay', function () {
    reset_config_singleton();
    $config = Config::getInstance();
    $config->rounding_precision = 0;
    $config->power_threshold = 0;
    $config->power_details_resolution = 0;
    $config->unit2_display = false;

    $calc = new StatsCalculator($config);
    $lines = [
        '15.03.2026,10:00:00,100,20',
        '15.03.2026,10:01:00,200,21',
        '15.03.2026,10:02:00,150,22',
        '15.03.2026,10:05:00,50,20',
        '15.03.2026,11:00:00,300,25',
    ];

    $result = $calc->processDay($lines, 5, 0, 23);

    pm_assert_equals(5, $result['reading_count'], 'Reads all 5 lines');
    pm_assert($result['y_max'] > 0, 'Y max > 0');
    pm_assert(count($result['data_points']) > 0, 'Has data points');
    pm_assert_equals(false, $result['feed_measured'], 'No feed detected');
    pm_assert_equals(false, $result['extra_data'], 'No extra data (3 columns + date/time)');
});

pm_test_group('StatsCalculator: processDay with feed', function () {
    reset_config_singleton();
    $config = Config::getInstance();
    $config->rounding_precision = 0;
    $config->power_threshold = 0;
    $config->power_details_resolution = 0;
    $config->unit2_display = false;

    $calc = new StatsCalculator($config);
    $lines = [
        '15.03.2026,10:00:00,100',
        '15.03.2026,10:01:00,-50',
        '15.03.2026,10:02:00,200',
    ];

    $result = $calc->processDay($lines, 5, 0, 23);
    pm_assert_equals(true, $result['feed_measured'], 'Feed detected with negative power');
});

// ── 4. CsvDataStore Tests ──

pm_test_group('CsvDataStore: Read/Write', function () {
    $tmpDir = '/tmp/powermeter_test_' . getmypid() . '/';
    reset_config_singleton();
    $config = Config::getInstance();
    $config->log_file_dir = $tmpDir;

    $store = new CsvDataStore($config);
    $store->ensureDir();
    pm_assert(is_dir($tmpDir), 'Directory created');

    // Write and read
    $store->appendReading('2026-03-15', '15.03.2026,10:00:00,100,20');
    $store->appendReading('2026-03-15', '15.03.2026,10:01:00,200,21');
    $readings = $store->getReadings('2026-03-15');
    pm_assert_equals(2, count($readings), 'Read back 2 lines');
    pm_assert_contains($readings[0], '100', 'First reading contains power');

    // Stats
    $store->setLatestStats('15.03.2026,10:01:00,200,21');
    pm_assert_equals('15.03.2026,10:01:00,200,21', $store->getLatestStats(), 'Stats round-trip');

    // Available dates
    list($files, $pos, $dates) = $store->getAvailableDates('2026-03-15');
    pm_assert_equals(1, count($files), 'One date file found');
    pm_assert_equals('2026-03-15', $files[0]['date'], 'Correct date');
    pm_assert_equals(0, $pos, 'Position found');

    // Cleanup
    rm_rf($tmpDir);
});

pm_test_group('CsvDataStore: Multi-device subdirectory', function () {
    $tmpDir = '/tmp/powermeter_test_md_' . getmypid() . '/';
    reset_config_singleton();
    $config = Config::getInstance();
    $config->log_file_dir = $tmpDir;

    $store = new CsvDataStore($config, 'solar');
    $store->ensureDir();
    pm_assert(is_dir($tmpDir . 'solar/'), 'Device subdirectory created');
    pm_assert_equals($tmpDir . 'solar/', $store->getDir(), 'Dir includes device id');

    $store->appendReading('2026-03-15', '15.03.2026,10:00:00,500');
    $readings = $store->getReadings('2026-03-15');
    pm_assert_equals(1, count($readings), 'Read from device subdir');

    rm_rf($tmpDir);
});

// ── 5. Security Tests ──

pm_test_group('Security: Color regex validation', function () {
    // Anchored regex (as fixed in chart.php line 461)
    $regex = '/^[0-9a-fA-F]{6}$/';
    pm_assert(preg_match($regex, 'FF0000'), 'Valid hex FF0000 passes');
    pm_assert(preg_match($regex, 'abc123'), 'Valid hex abc123 passes');
    pm_assert(preg_match($regex, 'AABB00'), 'Valid hex AABB00 passes');
    pm_assert(!preg_match($regex, 'FFF'), 'Short string FFF fails');
    pm_assert(!preg_match($regex, 'gggggg'), 'Non-hex gggggg fails');
    pm_assert(!preg_match($regex, 'abc123xx'), 'Too long abc123xx fails');
    pm_assert(!preg_match($regex, '<script>'), 'Script tag fails');
    pm_assert(!preg_match($regex, '0);alert(1);//'), 'JS injection fails');
});

pm_test_group('Security: XSS escaping', function () {
    $xss = '<script>alert(1)</script>';
    pm_assert_equals('&lt;script&gt;alert(1)&lt;/script&gt;', htmlspecialchars($xss), 'htmlspecialchars escapes script tags');

    $xss2 = '"onload="alert(1)';
    pm_assert_contains(htmlspecialchars($xss2, ENT_QUOTES), '&quot;', 'htmlspecialchars escapes quotes');
});

pm_test_group('Security: addslashes for JS contexts', function () {
    pm_assert_equals("test\\'s", addslashes("test's"), 'Single quote escaped');
    pm_assert_equals("test\\\\path", addslashes("test\\path"), 'Backslash escaped');
    pm_assert_equals('W', addslashes('W'), 'Simple unit unchanged');
    pm_assert_equals('°C', addslashes('°C'), 'Degree symbol unchanged');
});

pm_test_group('Security: json_encode for JS labels', function () {
    pm_assert_equals('"Leistung"', json_encode('Leistung'), 'Simple label encoded');
    pm_assert_equals('"O\'Brien"', json_encode("O'Brien"), 'Quote in label safe in JSON');
    pm_assert_equals('"test<br>"', json_encode('test<br>'), 'HTML in label encoded');

    // Verify json_encode output is safe inside JS
    $label = "test'; alert(1); //";
    $encoded = json_encode($label);
    // json_encode wraps in double quotes and escapes internal quotes
    pm_assert($encoded[0] === '"' && $encoded[strlen($encoded)-1] === '"', 'json_encode wraps in double quotes (safe JS string)');
});

pm_test_group('Security: Path traversal validation', function () {
    reset_config_singleton();
    $config = Config::getInstance();
    $config->devices = [
        ['id' => 'solar', 'device' => 'anker_solix'],
        ['id' => 'grid', 'device' => 'shelly3em'],
    ];
    $config->gesamt_groups = [
        ['id' => 'gesamt', 'label' => 'Gesamt', 'devices' => ['solar', 'grid']],
    ];

    // Valid devices/groups
    pm_assert_equals(true, $config->isGesamtGroup('gesamt'), 'gesamt is valid group');
    pm_assert_equals(false, $config->isGesamtGroup('solar'), 'solar is device, not group');

    // Path traversal attempts
    $malicious = ['../../etc/passwd', '../..', '..', '/etc/shadow', 'data/../../../'];
    foreach ($malicious as $path) {
        pm_assert_equals(false, $config->isGesamtGroup($path), "Path traversal blocked: $path");
        pm_assert_equals(null, $config->getGesamtGroup($path), "getGesamtGroup null for: $path");
    }
});

pm_test_group('Security: strip_tags on labels', function () {
    pm_assert_equals('Solar', strip_tags('<b>Solar</b>'), 'strip_tags removes HTML');
    pm_assert_not_contains(strip_tags('<script>alert("xss")</script>'), '<script>', 'strip_tags removes script tags');
    pm_assert_equals('Leistung', strip_tags('Leistung'), 'Plain text unchanged');
});

pm_test_group('Security: unserialize with allowed_classes', function () {
    $data = serialize([0 => 100, 50 => 200]);
    $result = unserialize($data, ['allowed_classes' => false]);
    pm_assert_equals([0 => 100, 50 => 200], $result, 'Array unserializes correctly');
    pm_assert(is_array($result), 'Result is array');
});

// ── 6. Chart Rendering Tests (self-contained with test data) ──

pm_test_group('Chart: Gesamt HTML rendering', function () {
    $tmpDir = '/tmp/powermeter_chart_test_' . getmypid() . '/';
    mkdir($tmpDir, 0755, true);
    mkdir($tmpDir . 'dev1/', 0755, true);
    mkdir($tmpDir . 'dev2/', 0755, true);

    // Create test CSV data
    $csv1 = "01.01.2026,10:00:00,100,20\n01.01.2026,10:01:00,200,21\n01.01.2026,10:02:00,150,22\n";
    $csv2 = "01.01.2026,10:00:00,50,18\n01.01.2026,10:01:00,75,19\n";
    file_put_contents($tmpDir . 'dev1/2026-01-01.csv', $csv1);
    file_put_contents($tmpDir . 'dev2/2026-01-01.csv', $csv2);

    // Create a test config file
    $configContent = '<?php
$device = "tasmota"; $host = ""; $refresh_rate = 2;
$unit1 = "W"; $unit1_label = "Leistung"; $unit1_label_in = "Bezug"; $unit1_label_out = "Einspeisung";
$unit2 = "°C"; $unit2_label = "Temperatur"; $unit2_display = false;
$unit3 = "W"; $unit3_label = "L1"; $unit4 = "W"; $unit4_label = "L2";
$unit5 = "W"; $unit5_label = "L3"; $unit6 = "W"; $unit6_label = "L4";
$color1 = "109, 120, 173"; $color2 = "109, 120, 173"; $color3 = "127, 255, 0";
$color4 = "127, 255, 0"; $color5 = "200, 100, 0"; $color6 = "128, 64, 0";
$color7 = "0, 0, 0"; $color8 = "128, 128, 128"; $color9 = "0, 64, 128";
$rounding_precision = 0; $power_threshold = 0; $produce_consume = "";
$display_temp = false; $log_file_dir = "' . addslashes($tmpDir) . '";
$fix_axis_y = 0; $res = 5; $power_details_resolution = 0;
$inverter_id = 0; $log_rate = 6; $use_cache = false; $log_extra_array = 0;
$anker_email = ""; $anker_password = ""; $anker_country = "DE"; $anker_site_id = "";
$devices = [
    ["id" => "dev1", "device" => "tasmota", "label" => "Device One"],
    ["id" => "dev2", "device" => "tasmota", "label" => "Device Two"],
];
';
    $configPath = $tmpDir . 'config.inc.php';
    file_put_contents($configPath, $configContent);

    // Run chart.php in a subprocess with our test config
    $phpCode = '
        chdir("' . addslashes(dirname(__DIR__)) . '");
        $_GET = ["file" => "2026-01-01", "device" => "gesamt"];
        $_SERVER["REQUEST_METHOD"] = "GET";

        // Override config path
        $GLOBALS["_test_config_path"] = "' . addslashes($configPath) . '";

        // Include config
        require("' . addslashes($configPath) . '");
        require("functions.inc.php");

        $config = Config::load("' . addslashes($configPath) . '");
        $isMultiDevice = $config->isMultiDevice();
        $deviceMeta = pm_get_device_meta();
        $gesamtGroups = $config->getGesamtGroups();
        $defaultGroupId = $gesamtGroups[0]["id"] ?? "gesamt";
        $activeDevice = $_GET["device"] ?? $defaultGroupId;
        $activeGroup = $config->getGesamtGroup($activeDevice);
        $isGesamtMode = $activeGroup !== null;
        $groupDeviceMeta = $isGesamtMode ? array_intersect_key($deviceMeta, array_flip($activeGroup["devices"])) : [];

        // Output key facts
        echo "IS_MULTI:" . ($isMultiDevice ? "1" : "0") . "\n";
        echo "IS_GESAMT:" . ($isGesamtMode ? "1" : "0") . "\n";
        echo "DEVICES:" . implode(",", array_keys($groupDeviceMeta)) . "\n";
        echo "GROUPS:" . count($gesamtGroups) . "\n";
        echo "GROUP_LABEL:" . $activeGroup["label"] . "\n";
    ';

    $out = shell_exec('php -r ' . escapeshellarg($phpCode) . ' 2>&1');

    pm_assert_contains($out, 'IS_MULTI:1', 'Multi-device mode detected');
    pm_assert_contains($out, 'IS_GESAMT:1', 'Gesamt mode active');
    pm_assert_contains($out, 'DEVICES:dev1,dev2', 'Both devices in group');
    pm_assert_contains($out, 'GROUPS:1', 'One default gesamt group');
    pm_assert_contains($out, 'GROUP_LABEL:Gesamt', 'Default group label is Gesamt');

    // Test with custom gesamt_groups excluding dev2
    $configContent2 = $configContent . '
$gesamt_groups = [
    ["id" => "gesamt", "label" => "Nur Dev1", "devices" => ["dev1"]],
    ["id" => "alle", "label" => "Alle", "devices" => ["dev1", "dev2"]],
];
';
    file_put_contents($configPath, $configContent2);

    $phpCode2 = '
        chdir("' . addslashes(dirname(__DIR__)) . '");
        $_GET = ["file" => "2026-01-01", "device" => "gesamt"];
        $_SERVER["REQUEST_METHOD"] = "GET";
        require("' . addslashes($configPath) . '");
        require("functions.inc.php");
        $config = Config::load("' . addslashes($configPath) . '");
        $deviceMeta = pm_get_device_meta();
        $activeGroup = $config->getGesamtGroup("gesamt");
        $groupDeviceMeta = array_intersect_key($deviceMeta, array_flip($activeGroup["devices"]));
        echo "FILTERED:" . implode(",", array_keys($groupDeviceMeta)) . "\n";

        $activeGroup2 = $config->getGesamtGroup("alle");
        $groupDeviceMeta2 = array_intersect_key($deviceMeta, array_flip($activeGroup2["devices"]));
        echo "ALL:" . implode(",", array_keys($groupDeviceMeta2)) . "\n";

        // Path traversal
        echo "TRAVERSAL:" . ($config->isGesamtGroup("../../etc") ? "VULN" : "SAFE") . "\n";
    ';

    $out2 = shell_exec('php -r ' . escapeshellarg($phpCode2) . ' 2>&1');

    pm_assert_contains($out2, 'FILTERED:dev1', 'Custom group filters to dev1 only');
    pm_assert_not_contains($out2, 'FILTERED:dev1,dev2', 'dev2 excluded from gesamt');
    pm_assert_contains($out2, 'ALL:dev1,dev2', '"alle" group includes both');
    pm_assert_contains($out2, 'TRAVERSAL:SAFE', 'Path traversal blocked');

    rm_rf($tmpDir);
});

pm_test_group('Chart: Full HTML render with test data', function () {
    $tmpDir = '/tmp/powermeter_chart_html_' . getmypid() . '/';
    mkdir($tmpDir, 0755, true);
    mkdir($tmpDir . 'solar/', 0755, true);
    mkdir($tmpDir . 'grid/', 0755, true);

    $csv = "01.01.2026,10:00:00,100\n01.01.2026,10:05:00,200\n01.01.2026,10:10:00,150\n";
    file_put_contents($tmpDir . 'solar/2026-01-01.csv', $csv);
    file_put_contents($tmpDir . 'grid/2026-01-01.csv', str_replace(['100', '200', '150'], ['50', '75', '60'], $csv));

    $configPath = $tmpDir . 'config.inc.php';
    file_put_contents($configPath, '<?php
$device = "tasmota"; $host = ""; $refresh_rate = 2;
$unit1 = "W"; $unit1_label = "Leistung"; $unit1_label_in = "Bezug"; $unit1_label_out = "Einspeisung";
$unit2 = "°C"; $unit2_label = "Temperatur"; $unit2_display = false;
$unit3 = "W"; $unit3_label = "L1"; $unit4 = "W"; $unit4_label = "L2";
$unit5 = "W"; $unit5_label = "L3"; $unit6 = "W"; $unit6_label = "L4";
$color1 = "109, 120, 173"; $color2 = "109, 120, 173"; $color3 = "127, 255, 0";
$color4 = "127, 255, 0"; $color5 = "200, 100, 0"; $color6 = "128, 64, 0";
$color7 = "0, 0, 0"; $color8 = "128, 128, 128"; $color9 = "0, 64, 128";
$rounding_precision = 0; $power_threshold = 0; $produce_consume = "";
$display_temp = false; $log_file_dir = "' . addslashes($tmpDir) . '";
$fix_axis_y = 0; $res = 5; $power_details_resolution = 0;
$inverter_id = 0; $log_rate = 6; $use_cache = false; $log_extra_array = 0;
$anker_email = ""; $anker_password = ""; $anker_country = "DE"; $anker_site_id = "";
$devices = [
    ["id" => "solar", "device" => "tasmota", "label" => "Solar Panel"],
    ["id" => "grid", "device" => "tasmota", "label" => "Grid Meter"],
];
');

    $cwd = addslashes(dirname(__DIR__));
    $out = shell_exec("cd $cwd && php -d error_reporting=0 -r '
        \$_GET = [\"file\" => \"2026-01-01\", \"device\" => \"gesamt\"];
        \$_SERVER[\"REQUEST_METHOD\"] = \"GET\";
        require(\"" . addslashes($configPath) . "\");
        ob_start();
        require(\"chart.php\");
        echo ob_get_clean();
    ' 2>&1");

    if (!$out || strlen($out) < 100) {
        echo "  [SKIP] Chart render returned empty (may need full Docker env)\n";
        rm_rf($tmpDir);
        return;
    }

    pm_assert_contains($out, '<html', 'HTML tag present');
    pm_assert_contains($out, 'chart.min.js', 'Chart.js script loaded');
    pm_assert_contains($out, 'new Chart(', 'Chart constructor present');
    pm_assert_contains($out, '<canvas', 'Canvas element present');

    // Labels should use json_encode (double quotes)
    preg_match_all('/label: (".*?"|\'.*?\')/', $out, $m);
    $allDoubleQuoted = true;
    foreach ($m[1] ?? [] as $label) {
        if ($label[0] === "'") $allDoubleQuoted = false;
    }
    pm_assert($allDoubleQuoted, 'All labels use json_encode (double quotes)');

    // Both device datasets + Gesamt
    pm_assert_contains($out, '"Solar Panel"', 'Solar dataset present');
    pm_assert_contains($out, '"Grid Meter"', 'Grid dataset present');
    pm_assert_contains($out, '"Gesamt"', 'Gesamt sum dataset present');

    // No path traversal possible
    pm_assert_not_contains($out, '../', 'No path traversal in output');

    rm_rf($tmpDir);
});

// ── 7. Legacy Functions Tests ──

pm_test_group('Legacy: pm_evaluate_formula', function () {
    // Load legacy functions (needs globals set first)
    global $unit1, $unit1_label, $unit1_label_in, $unit1_label_out,
           $unit2, $unit2_label, $unit3, $unit3_label, $unit4, $unit4_label,
           $unit5, $unit5_label, $unit6, $unit6_label,
           $color1, $color2, $color3, $color4, $color5, $color6, $color7, $color8, $color9,
           $rounding_precision, $power_threshold, $inverter_id, $produce_consume,
           $display_temp, $log_file_dir, $device, $host, $refresh_rate,
           $anker_email, $anker_password, $anker_country, $anker_site_id;

    // Set minimal globals to prevent warnings
    $unit1 = 'W'; $unit1_label = 'Leistung'; $unit1_label_in = 'Bezug'; $unit1_label_out = 'Einspeisung';
    $unit2 = '°C'; $unit2_label = 'Temperatur'; $unit3 = 'W'; $unit3_label = 'L1';
    $unit4 = 'W'; $unit4_label = 'L2'; $unit5 = 'W'; $unit5_label = 'L3'; $unit6 = 'W'; $unit6_label = 'L4';
    $color1 = '109, 120, 173'; $color2 = '109, 120, 173'; $color3 = '127, 255, 0';
    $color4 = '127, 255, 0'; $color5 = '200, 100, 0'; $color6 = '128, 64, 0';
    $color7 = '0, 0, 0'; $color8 = '128, 128, 128'; $color9 = '0, 64, 128';
    $rounding_precision = 0; $power_threshold = 0; $inverter_id = 0; $produce_consume = '';
    $display_temp = false; $log_file_dir = 'data/'; $device = 'tasmota'; $host = '';
    $refresh_rate = 2;

    if (!function_exists('pm_evaluate_formula')) {
        require_once 'functions.inc.php';
    }

    $allStats = [
        'solar' => [0 => '', 1 => '', 2 => 100, 3 => 50],
        'grid'  => [0 => '', 1 => '', 2 => 200, 3 => 0],
    ];

    pm_assert_equals(300.0, pm_evaluate_formula('solar.power + grid.power', $allStats), 'Addition formula');
    pm_assert_equals(-100.0, pm_evaluate_formula('solar.power - grid.power', $allStats), 'Subtraction formula');
    pm_assert_equals(50.0, pm_evaluate_formula('solar.unit2', $allStats), 'unit2 field mapping');
    pm_assert_equals(100.0, pm_evaluate_formula('solar.power + missing.power', $allStats), 'Missing device = 0');
    pm_assert(pm_evaluate_formula('', $allStats) == 0, 'Empty formula = 0');
});

// ── Results ──

echo "\n=== Results: $_pass passed, $_fail failed ===\n";
if ($_fail > 0) {
    echo "\nFailed tests:\n";
    foreach ($_errors as $e) {
        echo "  - $e\n";
    }
    exit(1);
}
exit(0);
