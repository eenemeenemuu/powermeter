<?php
require('config.inc.php');
require('functions.inc.php');

// Multi-device support: ?device=solar selects a specific device
$config = Config::load(file_exists('config.inc.php') ? 'config.inc.php' : null);
$requestedDevice = isset($_GET['device']) ? $_GET['device'] : null;
$isMultiDevice = $config->isMultiDevice();

// ── Multi-device mode ──
if ($isMultiDevice && !isset($_GET['d'])) {
    $dm = new DeviceManager($config);

    // Build per-device label/unit info
    $deviceMeta = pm_get_device_meta();
    $deviceIds = array_keys($deviceMeta);

    if (!$use_cache || isset($_GET['nocache'])) {
        $allResults = $dm->queryAllAsync();
    } else {
        // Read from cached stats files per device
        $allResults = [];
        foreach ($deviceIds as $id) {
            $deviceStatsPath = $log_file_dir . $id . '/stats.txt';
            if (file_exists($deviceStatsPath)) {
                $allResults[$id] = explode(',', file_get_contents($deviceStatsPath));
            } else {
                $allResults[$id] = [];
            }
        }
    }

    // Flatten stats per device (same logic as single-device)
    $allStats = [];
    $combinedPower = 0;
    foreach ($allResults as $id => $stats_array) {
        $stats = [];
        if (isset($stats_array[0]) && $stats_array[0] === 'error') {
            $stats = ['error' => $stats_array[1] ?? 'Unknown error'];
        } else {
            foreach ($stats_array as $value) {
                if (is_array($value)) {
                    foreach ($value as $array_value) {
                        $stats[] = $array_value;
                    }
                } else {
                    $stats[] = $value;
                }
            }
            if (isset($stats[2])) {
                $combinedPower += floatval($stats[2]);
            }
        }
        $allStats[$id] = $stats;
    }

    // AJAX: return JSON
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        $response = ['devices' => [], 'combined_power' => $combinedPower];
        foreach ($allStats as $id => $stats) {
            $response['devices'][$id] = ['stats' => $stats];
        }
        echo json_encode($response);
        die();
    }

    // HTML output
    $gesamtGroups = $config->getGesamtGroups();
    $firstGroupDevices = $gesamtGroups[0]['devices'] ?? $deviceIds;
    $firstGroupPower = 0;
    foreach ($firstGroupDevices as $gDevId) {
        if (isset($allStats[$gDevId][2])) {
            $firstGroupPower += floatval($allStats[$gDevId][2]);
        }
    }
    $title = round($firstGroupPower) . ' ' . htmlspecialchars($unit1) . ' ' . htmlspecialchars($gesamtGroups[0]['label'] ?? 'Gesamt');
    echo '<html><head><title>' . $title . '</title>';
    echo '<link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';
    echo '<script>var multiDevice = true; var refresh_rate = ' . ($refresh_rate * 1000) . '; var unit1 = \'' . htmlspecialchars($unit1, ENT_QUOTES) . '\'; var unit1_digits = false;';
    echo ' var deviceIds = ' . json_encode($deviceIds) . ';';
    // Pass per-device meta and gesamt groups to JS
    echo ' var deviceMeta = ' . json_encode($deviceMeta) . ';';
    echo ' var gesamtGroups = ' . json_encode($gesamtGroups) . ';';
    echo '</script><script src="js/index.js"></script>';
    echo '<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f5f5f5; color: #333; transition: background 0.3s, color 0.3s; }
body.dark { background: #1a1a2e; color: #e0e0e0; }
td { padding: 6px 10px; }
body.dark td { color: #e0e0e0; }
td.r { text-align: right; color: #888; font-size: 0.9em; }
body.dark td.r { color: #999; }
.pm-value { font-size: 1.5em; font-weight: 600; color: #333; }
body.dark .pm-value { color: #f0f0f0; }
.pm-unit { font-size: 0.85em; color: #888; }
body.dark .pm-unit { color: #aaa; }
.pm-container { display: flex; gap: 24px; justify-content: center; flex-wrap: wrap; padding: 24px 20px 12px; max-width: 900px; margin: 0 auto; }
.pm-box { background: #fff; border: none; border-radius: 12px; padding: 20px 24px; min-width: 260px; flex: 1; max-width: 420px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: background 0.3s, box-shadow 0.3s; }
body.dark .pm-box { background: #16213e; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
.pm-box h3 { margin: 0 0 14px; font-size: 1.1em; font-weight: 600; color: #555; letter-spacing: 0.02em; }
body.dark .pm-box h3 { color: #aaa; }
.pm-box table { width: 100%; }
.pm-total { text-align: center; margin: 8px 0 20px; }
.pm-total-value { font-size: 2em; font-weight: 700; color: #4fc3f7; }
.pm-total-label { font-size: 0.9em; color: #aaa; margin-bottom: 2px; }
.pm-total .pm-unit { color: #b0bec5; }
.pm-total-virtual .pm-total-value { font-size: 1.5em; }
.pm-footer { text-align: center; padding-bottom: 24px; }
.pm-footer p { margin: 8px 0; }
.pm-footer a, .pm-footer a:link, .pm-footer a:visited { color: #ccc !important; text-decoration: none; margin: 0 8px; font-weight: 500; }
.pm-footer a:hover { color: #fff !important; text-decoration: underline; }
.pm-toggle { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9em; color: #aaa; user-select: none; }
.pm-toggle input { width: 18px; height: 18px; accent-color: #5a7abf; cursor: pointer; }
body.dark .pm-toggle { color: #aaa; }
</style>';
    echo '</head><body' . (isset($_GET['dm']) ? ' class="dark"' : '') . '>';

    // Device boxes
    echo '<div class="pm-container">';
    foreach ($deviceIds as $id) {
        $stats = $allStats[$id];
        $meta = $deviceMeta[$id];
        echo '<div class="pm-box" id="box_' . htmlspecialchars($id) . '">';
        echo '<h3>' . htmlspecialchars($meta['label']) . '</h3>';
        echo '<table>';

        if (isset($stats['error'])) {
            echo '<tr><td colspan="2">Fehler: ' . $stats['error'] . '</td></tr>';
        } else {
            // Power (stats[2])
            echo '<tr><td class="r">' . htmlspecialchars($meta['unit1_label']) . '</td><td><span class="pm-value" id="power_' . htmlspecialchars($id) . '">' . (isset($stats[2]) ? $stats[2] : '-') . '</span> <span class="pm-unit">' . htmlspecialchars($meta['unit1']) . '</span></td></tr>';
            // Temp/unit2 (stats[3])
            if (isset($stats[3]) && $stats[3] !== '') {
                echo '<tr><td class="r">' . htmlspecialchars($meta['unit2_label']) . '</td><td><span class="pm-value" id="temp_' . htmlspecialchars($id) . '">' . $stats[3] . '</span> <span class="pm-unit" id="unit2_' . htmlspecialchars($id) . '">' . htmlspecialchars($meta['unit2']) . '</span></td></tr>';
            }
            // Extra units (stats[4+])
            if (isset($stats[4])) {
                for ($i = 4; $i < count($stats); $i++) {
                    $unitKey = 'unit' . ($i - 1);
                    $labelKey = 'unit' . ($i - 1) . '_label';
                    $label = isset($meta[$labelKey]) ? $meta[$labelKey] : ('L' . ($i - 3));
                    $unitStr = isset($meta[$unitKey]) ? $meta[$unitKey] : 'W';
                    echo '<tr><td class="r">' . htmlspecialchars($label) . '</td><td><span class="pm-value" id="l' . ($i - 3) . '_' . htmlspecialchars($id) . '">' . $stats[$i] . '</span> <span class="pm-unit">' . htmlspecialchars($unitStr) . '</span></td></tr>';
                }
            }
            // Time + Date
            echo '<tr><td class="r">Uhrzeit</td><td><span id="time_' . htmlspecialchars($id) . '">' . (isset($stats[1]) ? $stats[1] : '-') . '</span></td></tr>';
            echo '<tr><td class="r">Datum</td><td><span id="date_' . htmlspecialchars($id) . '">' . (isset($stats[0]) ? $stats[0] : '-') . '</span></td></tr>';
        }

        echo '</table></div>';
    }
    echo '</div>';

    // Combined power per gesamt group
    foreach ($gesamtGroups as $gi => $group) {
        $groupPower = 0;
        foreach ($group['devices'] as $gDevId) {
            if (isset($allStats[$gDevId][2])) {
                $groupPower += floatval($allStats[$gDevId][2]);
            }
        }
        $groupLabel = htmlspecialchars($group['label']);
        $groupElId = $gi === 0 ? 'combined_power' : 'combined_power_' . $gi;
        echo '<div class="pm-total"><div class="pm-total-label">' . $groupLabel . '</div><span class="pm-total-value" id="' . $groupElId . '">' . round($groupPower) . '</span> <span class="pm-unit">' . htmlspecialchars($unit1) . '</span></div>';
    }

    // Virtual totals
    if (!empty($config->virtual_totals)) {
        $fieldMap = ['power' => 2, 'unit2' => 3, 'unit3' => 4, 'unit4' => 5, 'unit5' => 6, 'unit6' => 7];
        foreach ($config->virtual_totals as $i => $vt) {
            $value = round(pm_evaluate_formula($vt['formula'], $allStats));
            $label = htmlspecialchars($vt['label']);
            $vtUnit = htmlspecialchars($vt['unit'] ?? $unit1);
            echo '<div class="pm-total pm-total-virtual"><div class="pm-total-label">' . $label . '</div><span class="pm-total-value" id="virtual_' . $i . '">' . $value . '</span> <span class="pm-unit">' . $vtUnit . '</span></div>';
        }
        // Pass virtual_totals config to JS for AJAX updates
        echo '<script>var virtualTotals = ' . json_encode(array_map(function($vt) {
            return ['label' => $vt['label'], 'unit' => $vt['unit'] ?? '', 'formula' => $vt['formula']];
        }, $config->virtual_totals)) . '; var fieldMap = ' . json_encode($fieldMap) . ';</script>';
    }

    // Footer: links + dark mode
    echo '<div class="pm-footer">';
    $defaultGroupId = htmlspecialchars($gesamtGroups[0]['id'] ?? 'gesamt');
    echo '<p><a href="chart.php?today&device=' . $defaultGroupId . '">Heute</a><a href="chart.php?yesterday&device=' . $defaultGroupId . '">Gestern</a><a href="overview.php?device=' . $defaultGroupId . '">Übersicht</a></p>';
    echo '<p><label class="pm-toggle"><input id="dark_mode" type="checkbox" onclick="set_colors();"' . (isset($_GET['dm']) ? ' checked="checked"' : '') . ' /> Dark Mode</label></p>';
    echo '</div>';

    echo '</body></html>';
    exit;
}

// ── Single-device mode (unchanged) ──
if (!$use_cache || isset($_GET['nocache'])) {
    if ($config->isMultiDevice() && $requestedDevice) {
        // Query a specific device in multi-device mode
        $found = false;
        foreach ($config->getDeviceConfigs() as $entry) {
            if ($entry['id'] === $requestedDevice) {
                $driver = DriverFactory::create($entry['type'], $entry['config']);
                $stats_array = $driver->getStats();
                $found = true;
                break;
            }
        }
        if (!$found) {
            $stats_array = GetStats(); // fallback to default
        }
    } else {
        $stats_array = GetStats();
    }
    if (isset($stats_array[0]) && $stats_array[0] == 'error') {
        die($stats_array[1]);
    }
    $stats = array();
    foreach ($stats_array as $value) {
        if (is_array($value)) {
            foreach ($value as $array_value) {
                $stats[] = $array_value;
            }
        } else {
            $stats[] = $value;
        }
    }
} else {
    // In multi-device mode with cache, read from device-specific stats file
    if ($config->isMultiDevice() && $requestedDevice && $requestedDevice !== 'default') {
        $deviceStatsPath = $log_file_dir . $requestedDevice . '/stats.txt';
        if (file_exists($deviceStatsPath)) {
            $stats = explode(',', file_get_contents($deviceStatsPath));
        } else {
            $stats = explode(',', file_get_contents($log_file_dir . 'stats.txt'));
        }
    } else {
        $stats = explode(',', file_get_contents($log_file_dir . 'stats.txt'));
    }
}

if (isset($_GET['ajax'])) {
    echo implode(',', $stats);
    die();
}

echo '<html><head><title>'.$stats[2].' '.$unit1.' '.(isset($stats[3]) && $stats[3] !== '' ? '/ '.$stats[3].' '.$unit2.' ' : '').'['.$stats[1].' '.$stats[0].']</title>';
echo '<link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';
echo '<script>var multiDevice = false; unit1_digits = false; refresh_rate = '.($refresh_rate*1000).'; unit1 = \''.$unit1.'\'</script><script src="js/index.js"></script>';
if (isset($_GET['d']) && (int)$_GET['d']) {
    $digits = (int)$_GET['d'];
    if (isset($_GET['exclude_unit']) || $digits < 0) {
        $unit1 = '';
        $digits = abs($digits);
    }
    echo '<script>unit1_digits = '.$digits.';</script>';
    echo '<style>@font-face { font-family: "digital-7"; src: url("css/digital-7 (mono).ttf"); } body { background-color: black; margin: 0; padding: 0; } span { font-family: digital-7; font-size: 100vmin; color: red; }</style>';
    echo '</head><body>';
    $power = round($stats[2]);
    if ($power > str_repeat('9', $digits)) {
        $power = str_repeat('9', $digits);
    } elseif ($power < '-'.str_repeat('9', $digits-1)) {
        $power = '-'.str_repeat('9', $digits-1);
    }
    $power = sprintf('%0'.$digits.'d', $power);
    echo '<span onclick="this.requestFullscreen();"><span id="power">'.$power.'</span>'.$unit1.'</span>';
} else {
    echo '<style>td { width: 50%; padding: 5px; } td.r { text-align: right; } span { font-size: x-large; }</style>';
    echo '</head><body>';
    echo '<table width="100%"><tr><td align="center"><table>';
    echo '<tr><td class="r">Aktuelle Leistung:</td><td><span id="power">'.$stats[2].'</span> '.$unit1.'</td></tr>';
    if (isset($stats[3]) && $stats[3] !== '') {
        echo '<tr><td class="r">'.$unit2_label.':</td><td><span id="temp">'.$stats[3].'</span> <span id="unit2">'.$unit2.'</span></td></tr>';
    }
    if (isset($stats[4])) {
        for ($i = 4; $i < count($stats); $i++) {
            echo '<tr><td class="r">'.${'unit'.($i-1).'_label'}.':</td><td><span id="l'.($i-3).'">'.$stats[$i].'</span> '.${'unit'.($i-1)}.'</td></tr>';
        }
    }
    echo '<tr><td class="r">Uhrzeit:</td><td><span id="time">'.$stats[1].'</span></td></tr>';
    echo '<tr><td class="r">Datum:</td><td><span id="date">'.$stats[0].'</span></td></tr>';
    echo '<tr><td class="r" valign="top">'.$unit1_label.':</td><td><p><a href="chart.php?today">Heute</a></p><p><a href="chart.php?yesterday">Gestern</a></p><p><a href="overview.php">Übersicht</a></p></td></tr>';
    echo '<tr><td class="r">Dark mode:</td><td><input id="dark_mode" type="checkbox" onclick="set_colors();"'.(isset($_GET['dm']) ? ' checked="checked"' : '').' /></td></tr>';
    echo '</table></td></tr></table>';
}
echo '</body></html>';

//EOF
