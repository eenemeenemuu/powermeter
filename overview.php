<?php

require('config.inc.php');
require('functions.inc.php');

// Multi-device resolution
$config = Config::load(file_exists('config.inc.php') ? 'config.inc.php' : null);
$isMultiDevice = $config->isMultiDevice();
$deviceMeta = pm_get_device_meta();
$activeDevice = null;
$deviceSubdir = null;
$deviceParam = '';
$isGesamtMode = false;
$activeGroup = null;
$groupDeviceMeta = [];

if ($isMultiDevice) {
    $gesamtGroups = $config->getGesamtGroups();
    $defaultGroupId = $gesamtGroups[0]['id'] ?? 'gesamt';
    $activeDevice = $_GET['device'] ?? $defaultGroupId;
    $deviceParam = '&device=' . htmlspecialchars($activeDevice);
    $activeGroup = $config->getGesamtGroup($activeDevice);
    $isGesamtMode = $activeGroup !== null;

    if (!$isGesamtMode && isset($deviceMeta[$activeDevice])) {
        $meta = $deviceMeta[$activeDevice];
        $unit1 = $meta['unit1'];
        $unit1_label = $meta['unit1_label'];
        $unit1_label_in = $meta['unit1_label_in'];
        $unit1_label_out = $meta['unit1_label_out'];
        $unit2 = $meta['unit2'];
        $unit2_label = $meta['unit2_label'];
        $deviceSubdir = $activeDevice;
    }
    if ($isGesamtMode) {
        $groupDeviceIds = $activeGroup['devices'];
        $groupDeviceMeta = array_intersect_key($deviceMeta, array_flip($groupDeviceIds));
    }
}

list($files, $pos, $file_dates) = pm_scan_log_file_dir($deviceSubdir);

echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

if (($_GET['update'] ?? '') == 'missing' && !$isGesamtMode) {
    $statsDir = $deviceSubdir ? $log_file_dir . $deviceSubdir . '/' : $log_file_dir;
    $statsFile = $statsDir . 'chart_stats.csv';
    if (file_exists($statsFile)) {
        foreach (explode("\n", file_get_contents($statsFile)) as $line) {
            $stat_parts = explode(',', $line);
            if ($stat_parts[0]) {
                $chart_stats_file_content[] = $stat_parts[0];
            }
        }
    }
    $detailsFile = $statsDir . 'chart_details_' . $power_details_resolution . '.csv';
    if (file_exists($detailsFile)) {
        foreach (explode("\n", file_get_contents($detailsFile)) as $line) {
            $stat_parts = explode(',', $line);
            if ($stat_parts[0]) {
                $chart_details_file_content[] = $stat_parts[0];
            }
        }
    }
    for ($i = count($files); $i > 0; $i--) {
        if (!in_array($files[$i]['date'], $chart_stats_file_content ?? []) || ($power_details_resolution && !in_array($files[$i]['date'], $chart_details_file_content ?? []))) {
            $protocol = (($_SERVER['HTTPS'] ?? 'off') != 'off') ? 'https' : 'http';
            file_get_contents($protocol.'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']).'/chart.php?file='.$files[$i]['date'].$deviceParam);
        }
    }
}

// Load chart stats - for gesamt, merge all device stats
if ($isMultiDevice && $isGesamtMode) {
    $chart_stats = [];
    $chart_stats_month = [];
    $chart_stats_month_feed = [];
    foreach ($groupDeviceMeta as $devId => $_) {
        list($devStats, $devMonth, $devMonthFeed) = pm_scan_chart_stats($devId);
        foreach ($devStats as $date => $data) {
            if (!isset($chart_stats[$date])) {
                $chart_stats[$date] = [0 => $date, 1 => 0, 2 => '', 3 => '', 4 => 0, 5 => ''];
            }
            $chart_stats[$date][1] += floatval($data[1]);
            // Peak: keep the highest
            if (isset($data[4]) && floatval($data[4]) > floatval($chart_stats[$date][4])) {
                $chart_stats[$date][4] = $data[4];
                $chart_stats[$date][5] = $data[5] ?? '';
            }
            // First/last times: widen the range
            if (isset($data[2]) && ($chart_stats[$date][2] === '' || $data[2] < $chart_stats[$date][2])) {
                $chart_stats[$date][2] = $data[2];
            }
            if (isset($data[3]) && ($chart_stats[$date][3] === '' || $data[3] > $chart_stats[$date][3])) {
                $chart_stats[$date][3] = $data[3];
            }
            if (isset($data[6])) {
                $chart_stats[$date][6] = ($chart_stats[$date][6] ?? 0) + floatval($data[6]);
            }
        }
        foreach ($devMonth as $year => $months) {
            foreach ($months as $month => $value) {
                $chart_stats_month[$year][$month] = ($chart_stats_month[$year][$month] ?? 0) + $value;
            }
        }
        foreach ($devMonthFeed as $year => $months) {
            foreach ($months as $month => $value) {
                $chart_stats_month_feed[$year][$month] = ($chart_stats_month_feed[$year][$month] ?? 0) + $value;
            }
        }
    }
    // Merge file_dates from all devices in group
    $file_dates = [];
    foreach ($groupDeviceMeta as $devId => $_) {
        list($devFiles, , $devDates) = pm_scan_log_file_dir($devId);
        $file_dates = array_merge($file_dates, $devDates);
    }
    $file_dates = array_unique($file_dates);
} else {
    list($chart_stats, $chart_stats_month, $chart_stats_month_feed) = pm_scan_chart_stats($deviceSubdir);
}
krsort($chart_stats_month);
krsort($chart_stats_month_feed);

$feed_measured = false;
foreach ($chart_stats as $data) {
    if (isset($data[6])) {
        $feed_measured = true;
        break;
    }
}

$power_details_max_count = 0;
if ($power_details_resolution && !$isGesamtMode) {
    $detailsDir = $deviceSubdir ? $log_file_dir . $deviceSubdir . '/' : $log_file_dir;
    $detailsFile = $detailsDir . 'chart_details_' . $power_details_resolution . '.csv';
    if (file_exists($detailsFile)) {
        foreach (explode("\n", file_get_contents($detailsFile)) as $line) {
            $stat_parts = explode(',', $line);
            if ($stat_parts[0]) {
                $power_details_wh[$stat_parts[0]] = unserialize(substr($line, strpos($line, ',') + 1));
                list($power_details_wh2[$stat_parts[0]], $power_details_wh3[$stat_parts[0]]) = pm_calculate_power_details($power_details_wh[$stat_parts[0]]);
                $power_details_max_count = max(count($power_details_wh[$stat_parts[0]]), $power_details_max_count);
            }
        }
    }
}

echo '<link rel="stylesheet" href="css/tablesort.css"><script src="js/tablesort.min.js"></script><script src="js/tablesort.number.min.js"></script>';
echo '<title>'.$unit1_label.'sübersicht</title><style>table, th, td { border: 1px solid black; border-collapse: collapse; padding: 3px; } td.v { text-align: right; } th { position: sticky; top: 0; background-color: white; background-clip: padding-box; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.5); } </style></head><body>🔌 <a href=".">Zur aktuellen Leistungsanzeige</a><br />';

if ($isMultiDevice) {
    echo '<br />';
    pm_render_device_tabs($activeDevice, $deviceMeta, '');
}
echo '<br />';

pm_print_monthly_overview($feed_measured ? $unit1_label_in : $unit1_label, $chart_stats_month, false, $deviceParam);
if ($feed_measured) {
    pm_print_monthly_overview($unit1_label_out, $chart_stats_month_feed, true, $deviceParam);
}

echo '<table border="1" id="daily" class="sort"><thead><tr><th data-sort-default>Datum</th><th>'.($feed_measured ? $unit1_label_in : $unit1_label).'<br />('.$unit1.'h)</th><th>von</th><th>bis</th><th>Peak<br />('.$unit1.')</th><th>um</th>';
if ($feed_measured) {
    echo '<th>'.$unit1_label_out.'<br />('.$unit1.'h)</th>';
}
if ($unit2 == '%' && !$isGesamtMode) {
    echo '<th>%<br />(min)</th>';
    echo '<th>%<br />(max)</th>';
}
for ($i = 0; $i < $power_details_max_count; $i++) {
    echo '<th>&lt;&nbsp;'.($i+1) * $power_details_resolution.'&nbsp;'.$unit1.'<br />('.$unit1.'h)</th>';
}
echo '</tr></thead><tbody>';

$file_dates_w_stats_data = array_unique(array_merge($file_dates, array_keys($chart_stats)));
rsort($file_dates_w_stats_data);
foreach ($file_dates_w_stats_data as $date) {
    $chartLink = "chart.php?file={$date}" . $deviceParam;
    echo "<tr><td>".(in_array($date, $file_dates) ? "<a href=\"{$chartLink}\">{$date}</a>" : $date)."</td><td class=\"v\">".($chart_stats[$date][1] ?? '')."</td><td>".($chart_stats[$date][2] ?? '')."</td><td>".($chart_stats[$date][3] ?? '')."</td><td class=\"v\">".($chart_stats[$date][4] ?? '')."</td><td>".($chart_stats[$date][5] ?? '')."</td>";
    if ($feed_measured) {
        echo '<td class="v">'.($chart_stats[$date][6] ?? (isset($chart_stats[$date]) ? '0' : '')).'</td>';
    }
    if ($unit2 == '%' && !$isGesamtMode) {
        echo '<td class="v">'.($chart_stats[$date][7] ?? '').'</td>';
        echo '<td class="v">'.($chart_stats[$date][8] ?? '').'</td>';
    }
    for ($i = 0; $i < $power_details_max_count; $i++) {
        echo '<td class="v">'.(isset($power_details_wh3[$date][$i * $power_details_resolution]) && $power_details_wh3[$date][$i * $power_details_resolution] ? pm_round($power_details_wh3[$date][$i * $power_details_resolution], true) : '').'</td>';
    }
    echo '</tr>';
}
echo '</tbody></table><script>new Tablesort(document.getElementById(\'daily\'), { descending: true });</script></body></html>';

//EOF
