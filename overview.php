<?php

require('config.inc.php');
require('functions.inc.php');

list($files, $pos, $file_dates) = pm_scan_log_file_dir();

echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

if ($_GET['update'] == 'missing') {
    foreach (explode("\n", file_get_contents($log_file_dir.'chart_stats.csv')) as $line) {
        $stat_parts = explode(',', $line);
        if ($stat_parts[0]) {
            $chart_stats_file_content[] = $stat_parts[0];
        }
    }
    foreach (explode("\n", file_get_contents($log_file_dir.'chart_details_'.$power_details_resolution.'.csv')) as $line) {
        $stat_parts = explode(',', $line);
        if ($stat_parts[0]) {
            $chart_details_file_content[] = $stat_parts[0];
        }
    }
    for ($i = count($files); $i > 0; $i--) {
        if (!in_array($files[$i]['date'], $chart_stats_file_content) || ($power_details_resolution && !in_array($files[$i]['date'], $chart_details_file_content))) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http';
            file_get_contents($protocol.'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']).'/chart.php?file='.$files[$i]['date']);
        }
    }
}

list($chart_stats, $chart_stats_month, $chart_stats_month_feed) = pm_scan_chart_stats();
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
if ($power_details_resolution) {
    foreach (explode("\n", file_get_contents($log_file_dir.'chart_details_'.$power_details_resolution.'.csv')) as $line) {
        $stat_parts = explode(',', $line);
        if ($stat_parts[0]) {
            $power_details_wh[$stat_parts[0]] = unserialize(substr($line, strpos($line, ',') + 1));
            list($power_details_wh2[$stat_parts[0]], $power_details_wh3[$stat_parts[0]]) = pm_calculate_power_details($power_details_wh[$stat_parts[0]]);
            $power_details_max_count = max(count($power_details_wh[$stat_parts[0]]), $power_details_max_count);
        }
    }
}

echo '<link rel="stylesheet" href="css/tablesort.css"><script src="js/tablesort.min.js"></script><script src="js/tablesort.number.min.js"></script>';
echo '<title>'.$produce_consume.'sübersicht</title><style>table, th, td { border: 1px solid black; border-collapse: collapse; padding: 3px; } td.v { text-align: right; } th { position: sticky; top: 0; background-color: white; background-clip: padding-box; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.5); } </style></head><body>🔌 <a href=".">Zur aktuellen Leistungsanzeige</a><br /><br />';

pm_print_monthly_overview($feed_measured ? 'Bezug' : $produce_consume, $chart_stats_month);
if ($feed_measured) {
    pm_print_monthly_overview('Einspeisung', $chart_stats_month_feed);
}

echo '<table border="1" id="daily" class="sort"><thead><tr><th data-sort-default>Datum</th><th>'.($feed_measured ? 'Bezug' : $produce_consume).'<br />(Wh)</th><th>von</th><th>bis</th><th>Peak<br />(W)</th><th>um</th>';
if ($feed_measured) {
    echo '<th>Einspeisung<br />(Wh)</th>';
}
for ($i = 0; $i < $power_details_max_count; $i++) {
    echo '<th>&lt;&nbsp;'.($i+1) * $power_details_resolution.'&nbsp;W<br />(Wh)</th>';
}
echo '</tr></thead><tbody>';

$file_dates_w_stats_data = array_unique(array_merge($file_dates, array_keys($chart_stats)));
rsort($file_dates_w_stats_data);
foreach ($file_dates_w_stats_data as $date) {
    echo "<tr><td>".(in_array($date, $file_dates) ? "<a href=\"chart.php?file={$date}\">{$date}</a>" : $date)."</td><td class=\"v\">{$chart_stats[$date][1]}</td><td>{$chart_stats[$date][2]}</td><td>{$chart_stats[$date][3]}</td><td class=\"v\">{$chart_stats[$date][4]}</td><td>{$chart_stats[$date][5]}</td>";
    if ($feed_measured) {
        echo '<td class="v">'.(isset($chart_stats[$date][6]) ? $chart_stats[$date][6] : (isset($chart_stats[$date]) ? '-' : '')).'</td>';
    }
    for ($i = 0; $i < $power_details_max_count; $i++) {
        echo '<td class="v">'.($power_details_wh3[$date][$i * $power_details_resolution] ? pm_round($power_details_wh3[$date][$i * $power_details_resolution]) : '').'</td>';
    }
    echo '</tr>';
}
echo '</tbody></table><script>new Tablesort(document.getElementById(\'daily\'), { descending: true });</script></body></html>';

//EOF
