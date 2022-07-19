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
    for ($i = count($files); $i >= 0; $i--) {
        if (!in_array($files[$i]['date'], $chart_stats_file_content) || !in_array($files[$i]['date'], $chart_details_file_content)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http';
            file_get_contents($protocol.'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']).'/chart.php?file='.$files[$i]['date']);
        }
    }
}

list($chart_stats, $chart_stats_month) = pm_scan_chart_stats();
krsort($chart_stats_month);

$power_details_max_count = 0;
if ($power_details_resolution) {
    foreach (explode("\n", file_get_contents($log_file_dir.'chart_details_'.$power_details_resolution.'.csv')) as $line) {
        $stat_parts = explode(',', $line);
        if ($stat_parts[0]) {
            $power_details[$stat_parts[0]] = unserialize(substr($line, strpos($line, ',') + 1));
            $power_details_max_count = max(count($power_details[$stat_parts[0]]), $power_details_max_count);
        }
    }
}

echo '<link rel="stylesheet" href="css/tablesort.css"><script src="js/tablesort.min.js"></script><script src="js/tablesort.number.min.js"></script>';
echo '<title>'.$produce_consume.'sübersicht</title><style>table, th, td { border: 1px solid black; border-collapse: collapse; padding: 3px; } td.v { text-align: right; } th { position: sticky; top: 0; background-color: white; background-clip: padding-box; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.5); } </style></head><body>🔌 <a href=".">Zur aktuellen Leistungsanzeige</a><br /><br />';

echo '<table border="1"><tr><td colspan="14" align="center">'.$produce_consume.' pro Monat in kWh</td></tr><tr><th></th><th>01</th><th>02</th><th>03</th><th>04</th><th>05</th><th>06</th><th>07</th><th>08</th><th>09</th><th>10</th><th>11</th><th>12</th><th>∑</th>';
foreach ($chart_stats_month as $year => $months) {
    echo '<tr><td><strong>'.$year.'</strong></td>';
    $month_array = array('01' => '', '02' => '', '03' => '', '04' => '', '05' => '', '06' => '', '07' => '', '08' => '', '09' => '', '10' => '', '11' => '', '12' => '');
    $year_sum = 0;
    foreach ($months as $month => $value) {
        $month_array[$month] = $value;
        $year_sum += $value;
    }
    foreach ($month_array as $key => $value) {
        echo '<td>'.($value ? '<a href="chart.php?m='.$year.'-'.$key.'">'.round($value/1000, 2).'</a>' : '-').'</td>';
    }
    echo '<td>'.($year_sum ? '<a href="chart.php?y='.$year.'">'.round($year_sum/1000, 2) : '-').'</a></td>';
    echo '</tr>';
}
echo '</table><br />';

echo '<table border="1" id="daily" class="sort"><thead><tr><th data-sort-default>Datum</th><th>'.$produce_consume.'<br />(Wh)</th><th>von</th><th>bis</th><th>Peak<br />(W)</th><th>um</th>';
for ($i = 0; $i < $power_details_max_count; $i++) {
    echo '<th>'.($i ? '&ge;' : '&gt;').' '.$i * $power_details_resolution.' W</th>';
}
echo '</tr></thead><tbody>';

$file_dates_w_stats_data = array_unique(array_merge($file_dates, array_keys($chart_stats)));
rsort($file_dates_w_stats_data);
foreach ($file_dates_w_stats_data as $date) {
    echo "<tr><td>".(in_array($date, $file_dates) ? "<a href=\"chart.php?file={$date}\">{$date}</a>" : $date)."</td><td class=\"v\">{$chart_stats[$date][1]}</td><td>{$chart_stats[$date][2]}</td><td>{$chart_stats[$date][3]}</td><td class=\"v\">{$chart_stats[$date][4]}</td><td>{$chart_stats[$date][5]}</td>";
    for ($i = 0; $i < $power_details_max_count; $i++) {
        echo '<td>'.$power_details[$date][$i * $power_details_resolution].'</td>';
    }
    echo '</tr>';
}
echo '</tbody></table><script>new Tablesort(document.getElementById(\'daily\'), { descending: true });</script></body></html>';

//EOF
