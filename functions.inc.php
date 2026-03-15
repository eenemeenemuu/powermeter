<?php

if (defined('PM_FUNCTIONS_LOADED')) return;
define('PM_FUNCTIONS_LOADED', true);

// Load autoloader for all src/ classes
require_once __DIR__ . '/src/autoload.php';

if (!$unit1) {
    $unit1 = 'W';
}
if (!$unit1_label) {
    $unit1_label = $produce_consume ? $produce_consume : 'Leistung';
}
if (!$unit1_label_in) {
    $unit1_label_in = 'Bezug';
}
if (!$unit1_label_out) {
    $unit1_label_out = 'Einspeisung';
}
if (!$unit2) {
    $unit2 = '°C';
}
if (!$unit2_label) {
    $unit2_label = 'Temperatur';
}
if (!isset($unit2_display)) {
    // backward compatibility
    $unit2_display = $display_temp;
}
if (!$unit3) {
    $unit3 = 'W';
}
if (!$unit3_label) {
    $unit3_label = 'L1';
}
if (!$unit4) {
    $unit4 = 'W';
}
if (!$unit4_label) {
    $unit4_label = 'L2';
}
if (!$unit5) {
    $unit5 = 'W';
}
if (!$unit5_label) {
    $unit5_label = 'L3';
}
if (!$unit6) {
    $unit6 = 'W';
}
if (!$unit6_label) {
    $unit6_label = 'L4';
}
if (!$color1) {
    $color1 = '109, 120, 173';
}
if (!$color2) {
    $color2 = '109, 120, 173';
}
if (!$color3) {
    $color3 = '127, 255, 0';
}
if (!$color4) {
    $color4 = '127, 255, 0';
}
if (!$color5) {
    $color5 = '200, 100, 0';
}
if (!$color6) {
    $color6 = '128, 64, 0';
}
if (!$color7) {
    $color7 = '0, 0, 0';
}
if (!$color8) {
    $color8 = '128, 128, 128';
}
if (!$color9) {
    $color9 = '0, 64, 128';
}
if (!isset($inverter_id) || !$inverter_id) {
    // backward compatibility
    $inverter_id = 0;
}
if (!isset($anker_email) || !$anker_email) {
    $anker_email = '';
}
if (!isset($anker_password) || !$anker_password) {
    $anker_password = '';
}
if (!isset($anker_country) || !$anker_country) {
    $anker_country = 'DE';
}
if (!isset($anker_site_id) || !$anker_site_id) {
    $anker_site_id = '';
}

function GetStats() {
    global $device, $host, $user, $pass, $ain, $station_id, $inverter_id,
           $anker_email, $anker_password, $anker_country, $anker_site_id,
           $log_file_dir, $rounding_precision, $power_threshold;

    $dc = new DriverConfig();
    $dc->host = $host ?: '';
    $dc->user = $user ?: '';
    $dc->pass = $pass ?: '';
    $dc->ain = $ain ?: '';
    $dc->station_id = $station_id ?: '';
    $dc->inverter_id = (int) ($inverter_id ?: 0);
    $dc->anker_email = $anker_email ?: '';
    $dc->anker_password = $anker_password ?: '';
    $dc->anker_country = $anker_country ?: 'DE';
    $dc->anker_site_id = $anker_site_id ?: '';
    $dc->log_file_dir = $log_file_dir ?: 'data/';
    $dc->rounding_precision = (int) ($rounding_precision ?: 0);
    $dc->power_threshold = (float) ($power_threshold ?: 0);

    try {
        $driver = DriverFactory::create($device, $dc);
        return $driver->getStats();
    } catch (RuntimeException $e) {
        return ['error', $e->getMessage()];
    }
}

function date_dot2dash($date) {
    $date_parts = explode('.', $date);
    return "{$date_parts[2]}-{$date_parts[1]}-{$date_parts[0]}";
}

function pm_round($value, $number_format = false, $max_precision_level = 9) {
    global $rounding_precision, $power_threshold;
    if ($value === null) {
        return null;
    }
    if ($value < $power_threshold) {
        return 0;
    }
    if ($number_format && $rounding_precision) {
        return number_format($value, min($rounding_precision, $max_precision_level), '.', '');
    } else {
        return round($value, $rounding_precision);
    }
}

function pm_scan_log_file_dir($subdir = null) {
    global $log_file_dir;
    $dir = $subdir ? $log_file_dir . $subdir . '/' : $log_file_dir;
    $i = 0;
    $pos = false;
    $files = [];
    $file_dates = [];
    if (!is_dir($dir)) {
        return [$files, $pos, $file_dates];
    }
    foreach (scandir($dir, SCANDIR_SORT_DESCENDING) as $file) {
        if ($file == '.' || $file == '..' || is_dir($dir . $file) || $file == 'stats.txt' || $file == 'chart_stats.csv' || substr($file, 0, 14) == 'chart_details_' || $file == 'buffer.txt' || $file == 'power_array' || $file == 'anker_token.json' || $file == 'anker_mqtt_cache.json' || substr($file, 0, 5) == 'mqtt_') {
            continue;
        }
        if (isset($_GET['file']) && ($file == $_GET['file'] || $file == $_GET['file'].'.csv' || $file == $_GET['file'].'.csv.gz' || $file == $_GET['file'].'.gz')) {
            $pos = $i;
        }
        $i++;
        $files[] = array('date' => substr($file, 0, strpos($file, '.')), 'name' => $file);
    }
    foreach ($files as $key => $file) {
        $file_dates[] = $file['date'];
    }
    $file_dates = array_unique($file_dates);
    return [$files, $pos, $file_dates];
}

function pm_scan_chart_stats($subdir = null) {
    global $log_file_dir, $file_dates;
    $chart_stats = [];
    $chart_stats_month = [];
    $chart_stats_month_feed = [];
    $dir = $subdir ? $log_file_dir . $subdir . '/' : $log_file_dir;
    $stats_file = $dir.'chart_stats.csv';
    if (!file_exists($stats_file)) {
        return [$chart_stats, $chart_stats_month, $chart_stats_month_feed];
    }
    foreach (explode("\n", file_get_contents($stats_file)) as $line) {
        $stat_parts = explode(',', $line);
        if ($stat_parts[0]) {
            $chart_stats[$stat_parts[0]] = $stat_parts;
            $date_parts = explode('-', $stat_parts[0]);
            $chart_stats_month[$date_parts[0]][$date_parts[1]] = ($chart_stats_month[$date_parts[0]][$date_parts[1]] ?? 0) + $stat_parts[1];
            if (isset($stat_parts[6])) {
                $chart_stats_month_feed[$date_parts[0]][$date_parts[1]] = ($chart_stats_month_feed[$date_parts[0]][$date_parts[1]] ?? 0) + $stat_parts[6];
            }
        }
    }
    return [$chart_stats, $chart_stats_month, $chart_stats_month_feed];
}

function pm_print_monthly_overview($header, $data, $feed = false, $deviceParam = '') {
    global $unit1;
    $feed = $feed ? '&feed' : '';
    echo '<table border="1"><tr><td colspan="14" align="center">'.$header.' pro Monat in k'.$unit1.'h</td></tr><tr><th></th><th>01</th><th>02</th><th>03</th><th>04</th><th>05</th><th>06</th><th>07</th><th>08</th><th>09</th><th>10</th><th>11</th><th>12</th><th>∑</th>';
    foreach ($data as $year => $months) {
        echo '<tr><td><strong>'.$year.'</strong></td>';
        $month_array = array('01' => '', '02' => '', '03' => '', '04' => '', '05' => '', '06' => '', '07' => '', '08' => '', '09' => '', '10' => '', '11' => '', '12' => '');
        $year_sum = 0;
        foreach ($months as $month => $value) {
            $month_array[$month] = $value;
            $year_sum += $value;
        }
        foreach ($month_array as $key => $value) {
            echo '<td>'.($value ? '<a href="chart.php?m='.$year.'-'.$key.$feed.$deviceParam.'">'.number_format($value/1000, 2, '.', '').'</a>' : '-').'</td>';
        }
        echo '<td>'.($year_sum ? '<a href="chart.php?y='.$year.$feed.$deviceParam.'">'.number_format($year_sum/1000, 2, '.', '') : '-').'</a></td>';
        echo '</tr>';
    }
    echo '</table><br />';
}

function pm_calculate_power_details($power_details_wh) {
    $key_last = false;
    $power_details_wh2 = $power_details_wh;
    foreach ($power_details_wh as $key => $value) {
        if ($key_last !== false) {
            $power_details_wh2[$key_last] -= $value;
        }
        $key_last = $key;
    }
    $power_details_wh3_sum = 0;
    $power_details_wh3 = [];
    foreach ($power_details_wh2 as $key => $value) {
        $power_details_wh3_sum += $value;
        $power_details_wh3[$key] = $power_details_wh3_sum;
    }
    return [$power_details_wh2, $power_details_wh3];
}

function pm_get_device_meta() {
    global $unit1, $unit1_label, $unit1_label_in, $unit1_label_out,
           $unit2, $unit2_label, $unit3, $unit3_label, $unit4, $unit4_label,
           $unit5, $unit5_label, $unit6, $unit6_label;
    $config = Config::getInstance();
    if (!$config->isMultiDevice()) {
        return [];
    }
    $deviceMeta = [];
    foreach ($config->devices as $i => $entry) {
        $id = $entry['id'] ?? 'device_' . $i;
        $deviceMeta[$id] = [
            'label'          => $entry['label'] ?? ucfirst($id),
            'unit1'          => $entry['unit1'] ?? $unit1,
            'unit1_label'    => $entry['unit1_label'] ?? $unit1_label,
            'unit1_label_in' => $entry['unit1_label_in'] ?? $unit1_label_in,
            'unit1_label_out'=> $entry['unit1_label_out'] ?? $unit1_label_out,
            'unit2'          => $entry['unit2'] ?? $unit2,
            'unit2_label'    => $entry['unit2_label'] ?? $unit2_label,
            'unit2_display'  => $entry['unit2_display'] ?? $entry['display_temp'] ?? false,
            'unit3'          => $entry['unit3'] ?? $unit3,
            'unit3_label'    => $entry['unit3_label'] ?? $unit3_label,
            'unit4'          => $entry['unit4'] ?? $unit4,
            'unit4_label'    => $entry['unit4_label'] ?? $unit4_label,
            'unit5'          => $entry['unit5'] ?? $unit5,
            'unit5_label'    => $entry['unit5_label'] ?? $unit5_label,
            'unit6'          => $entry['unit6'] ?? $unit6,
            'unit6_label'    => $entry['unit6_label'] ?? $unit6_label,
            'color1'         => $entry['color1'] ?? null,
        ];
    }
    return $deviceMeta;
}

function pm_evaluate_formula($formula, $allStats) {
    $fieldMap = ['power' => 2, 'unit2' => 3, 'unit3' => 4, 'unit4' => 5, 'unit5' => 6, 'unit6' => 7];

    // Split on + and - while preserving operators
    $tokens = preg_split('/\s*([+\-])\s*/', trim($formula), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $result = 0;
    $op = '+';
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '+' || $token === '-') {
            $op = $token;
            continue;
        }
        // Parse device.field
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) continue;
        list($deviceId, $field) = $parts;
        $index = isset($fieldMap[$field]) ? $fieldMap[$field] : null;
        if ($index === null) continue;
        $value = isset($allStats[$deviceId][$index]) ? floatval($allStats[$deviceId][$index]) : 0;
        if ($op === '+') {
            $result += $value;
        } else {
            $result -= $value;
        }
    }
    return $result;
}

function pm_render_device_tabs($activeDevice, $deviceMeta, $queryParams = '') {
    $config = Config::getInstance();
    $tabs = '<div style="text-align: center; margin: 8px 0;">';
    // Gesamt group tabs
    foreach ($config->getGesamtGroups() as $group) {
        $style = $activeDevice === $group['id'] ? 'font-weight: bold; text-decoration: underline;' : '';
        $tabs .= '<a href="?' . $queryParams . '&device=' . htmlspecialchars($group['id']) . '" style="margin: 0 8px; ' . $style . '">' . htmlspecialchars($group['label']) . '</a>';
    }
    // Per-device tabs
    foreach ($deviceMeta as $id => $meta) {
        $style = $activeDevice === $id ? 'font-weight: bold; text-decoration: underline;' : '';
        $tabs .= '<a href="?' . $queryParams . '&device=' . htmlspecialchars($id) . '" style="margin: 0 8px; ' . $style . '">' . htmlspecialchars($meta['label']) . '</a>';
    }
    $tabs .= '</div>';
    echo $tabs;
}

//EOF
