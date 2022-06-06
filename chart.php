<?php

require('config.inc.php');

$i = 0;
$pos = false;
$files = array();
foreach (scandir($log_file_dir, SCANDIR_SORT_DESCENDING) as $file) {
    if ($file == '.' || $file == '..' || $file == 'stats.txt' || $file == 'chart_stats.csv' || substr($file, 0, 14) == 'chart_details_' || $file == 'buffer.txt') {
        continue;
    }
    if (isset($_GET['file']) && ($file == $_GET['file'] || $file == $_GET['file'].'.csv' || $file == $_GET['file'].'.csv.gz' || $file == $_GET['file'].'.gz')) {
        $pos = $i;
    }
    $i++;
    $files[] = array('date' => substr($file, 0, strpos($file, '.')), 'name' => $file);
}

if (isset($_GET['today'])) {
    header("Location: chart.php?file={$files[0]['name']}");
}

if (isset($_GET['yesterday'])) {
    header("Location: chart.php?file={$files[1]['name']}");
}

echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

if (!isset($_GET['file'])) {
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
                file_get_contents($protocol.'://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].'?file='.$files[$i]['date']);
            }
        }
    }

    foreach ($files as $key => $file) {
        $file_dates[] = $file['date'];
    }
    array_unique($file_dates);

    foreach (explode("\n", file_get_contents($log_file_dir.'chart_stats.csv')) as $line) {
        $stat_parts = explode(',', $line);
        if ($stat_parts[0] && in_array($stat_parts[0], $file_dates)) {
            $chart_stats[$stat_parts[0]] = $stat_parts;
            $date_parts = explode('-', $stat_parts[0]);
            $chart_stats_month[$date_parts[0]][$date_parts[1]] += $stat_parts[1];
        }
    }
    krsort($chart_stats_month);

    $power_details_max_count = 0;
    if ($power_details_resolution) {
        foreach (explode("\n", file_get_contents($log_file_dir.'chart_details_'.$power_details_resolution.'.csv')) as $line) {
            $stat_parts = explode(',', $line);
            if ($stat_parts[0] && in_array($stat_parts[0], $file_dates)) {
                $power_details[$stat_parts[0]] = unserialize(substr($line, strpos($line, ',') + 1));
                $power_details_max_count = max(count($power_details[$stat_parts[0]]), $power_details_max_count);
            }
        }
    }

    echo '<link rel="stylesheet" href="tablesort.css"><script src="tablesort.min.js"></script><script src="tablesort.number.min.js"></script>';
    echo '<title>'.$produce_consume.'sübersicht</title><style>table, th, td { border: 1px solid black; border-collapse: collapse; padding: 3px; } td.v { text-align: right; } th { position: sticky; top: 0; background-color: white; background-clip: padding-box; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.5); } </style></head><body><a href=".">Zurück zur aktuellen Leistungsanzeige</a><br /><br />';

    echo '<table border="1"><tr><td colspan="14" align="center">'.$produce_consume.' pro Monat in kWh</td></tr><tr><th></th><th>01</th><th>02</th><th>03</th><th>04</th><th>05</th><th>06</th><th>07</th><th>08</th><th>09</th><th>10</th><th>11</th><th>12</th><th>∑</th>';
    foreach ($chart_stats_month as $year => $months) {
        echo '<tr><td><strong>'.$year.'</strong></td>';
        $month_array = array('01' => '', '02' => '', '03' => '', '04' => '', '05' => '', '06' => '', '07' => '', '08' => '', '09' => '', '10' => '', '11' => '', '12' => '');
        $year_sum = 0;
        foreach ($months as $month => $value) {
            $month_array[$month] = $value;
            $year_sum += $value;
        }
        foreach ($month_array as $value) {
            echo '<td>'.($value ? round($value/1000, 2) : '-').'</td>';
        }
        echo '<td><strong>'.($year_sum ? round($year_sum/1000, 2) : '-').'</strong></td>';
        echo '</tr>';
    }
    echo '</table><br />';

    echo '<table border="1" id="daily" class="sort"><thead><tr><th data-sort-default>Datum</th><th>'.$produce_consume.'<br />(Wh)</th><th>von</th><th>bis</th><th>Peak<br />(W)</th><th>um</th>';
    for ($i = 0; $i < $power_details_max_count; $i++) {
        echo '<th>&gt; '.$i * $power_details_resolution.' W</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($file_dates as $date) {
        echo "<tr><td><a href=\"?file={$date}\">{$date}</a></td><td class=\"v\">{$chart_stats[$date][1]}</td><td>{$chart_stats[$date][2]}</td><td>{$chart_stats[$date][3]}</td><td class=\"v\">{$chart_stats[$date][4]}</td><td>{$chart_stats[$date][5]}</td>";
        for ($i = 0; $i < $power_details_max_count; $i++) {
            echo '<td>'.$power_details[$date][$i * $power_details_resolution].'</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table><script>new Tablesort(document.getElementById(\'daily\'), { descending: true });</script></body></html>';
} else {
    if ($pos === false) {
        die('Error! File not found: '.htmlentities($_GET['file']));
    }
    function power_stats($value) {
        global $power_stats, $power_details, $power_details_wh, $power_details_resolution, $device;
        if (!$power_stats['first'] && $value['p']) {
            $power_stats['first'] = $value;
        }
        if ($value['p']) {
            $power_stats['last'] = $value;
        }
        if ($value['p'] && $value['p'] > $power_stats['peak']['p']) {
            $power_stats['peak'] = $value;
        }
        if ($power_stats['last_p']) {
            $limit = $device == 'envtec' ? 300 : 100;
            $now = mktime($value['h'], $value['m'], $value['s']);
            if ($now - $power_stats['last_timestamp'] < $limit) {
                // only calculate if the values are not too far apart in time
                if ($power_details_resolution) {
                    for ($i = 0; $i <= $power_stats['last_p']; $i += $power_details_resolution) {
                        $power_details[$i] += $now - $power_stats['last_timestamp'];
                        $power_details_wh[$i] += $power_stats['last_p'] * ($now - $power_stats['last_timestamp']) / 60 / 60;;
                    }
                }
                $power_stats['wh'] += $power_stats['last_p'] * ($now - $power_stats['last_timestamp']) / 60 / 60;
            }
        }
        $power_stats['last_p'] = $value['p'];
        $power_stats['last_timestamp'] = mktime($value['h'], $value['m'], $value['s']);
    }
    function compress_file($file, $data) {
        global $log_file_dir;
        if (function_exists('gzencode')) {
            $data = implode("\n", $data);
            $gzdata = gzencode($data, 7);
            if ($gzdata) {
                if (file_put_contents($log_file_dir.$file.'.gz', $gzdata)) {
                    if (unlink($log_file_dir.$file)) {
                        return $file.'.gz';
                    }
                }
            }
        }
        return $file;
    }
    $res = $_GET['res'] ? $_GET['res'] : $res;
    $t1 = isset($_GET['t1']) ? $_GET['t1'] : 0;
    $t2 = isset($_GET['t2']) ? $_GET['t2'] : 23;
    if ($t1 > $t2) {
        $t1 = $t2;
    }
    if (isset($_GET['max']) || isset($_GET['follow'])) {
        $res = -1;
        $t1 = 0;
        $t2 = 23;
    }
    $data = file_get_contents($log_file_dir.$files[$pos]['name']);
    $file_is_compressed = false;
    if (strpos($files[$pos]['name'], '.gz') !== false) {
        $file_is_compressed = true;
        $data = gzdecode($data);
    } elseif ($files[$pos]['date'] == $files[$pos-1]['date']) {
        $data2 = file_get_contents($log_file_dir.$files[$pos-1]['name']);
        $data .= gzdecode($data2);
    }
    if (isset($_GET['download'])) {
        header('Content-type: text/csv');
        header('Content-Disposition: attachment; filename="'.$files[$pos]['date'].'.csv"');
        ob_clean();
        flush();
        echo $data;
        die();
    }
    $lines = array_unique(explode("\n", $data));
    $date = substr($lines[0], 0, 10);
    $wh = 0;
    $dataPoints = array();
    $dataPoints_t = array();
    $dataPoints_wh = array();
    $power_stats = array('first' => array(), 'last' => array(), 'peak' => array('p' => 0));
    $power_details = array();
    $power_details_wh = array();
    $temp_measured = false;
    $data = array();
    foreach ($lines as $line) {
        if (trim($line)) {
            $data_this = explode(",", $line);
            $time_parts = explode(":", $data_this[1]);
            if ($display_temp && isset($data_this[3])) {
                $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2], 't' => $data_this[3]);
            } else {
                $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2]);
            }
        }
    }
    if ($res == -1) {
        $last_p = 0;
        $last_timestamp = 0;
        foreach ($data as $value) {
            if ($value['h'] >= $t1 && $value['h'] <= $t2) {
                power_stats($value);
                $dataPoints[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => $value['p']);
                $dataPoints_wh[] = round($power_stats['wh']);
                if (isset($value['t'])) {
                    $temp_measured = true;
                    $dataPoints_t[] = $value['t'];
                }
            }
        }
        if (isset($_GET['max'])) {
            header("Location: chart.php?file={$files[$pos]['date']}&res=-1&fix=0&t1={$power_stats['first']['h']}&t2={$power_stats['last']['h']}");
        }
        if (isset($_GET['follow'])) {
            header("Location: chart.php?file={$files[$pos]['date']}&res=-1&fix=0&t1={$power_stats['first']['h']}&t2=23&refresh=on");
        }
    } else {
        if ($t1 === 0) {
            $t1 = '0';
        }
        for ($h = $t1; $h <= $t2; $h++) {
            for ($m = 0; $m < 60; $m = $m + $res) {
                $p_res = array();
                $t_res = array();
                $y = null;
                foreach ($data as $value) {
                    if ($value['h'] == $h && ($value['m'] >= $m && $value['m'] < $m + $res)) {
                        $p_res[] = $value['p'];
                        if (isset($value['t'])) {
                            $t_res[] = $value['t'];
                        }
                        power_stats($value);
                    }
                }
                if (count($p_res)) {
                    $y = round(array_sum($p_res) / count($p_res));
                }
                $dataPoints[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => $y);
                if (count($p_res)) {
                    $dataPoints_wh[] = round($power_stats['wh']);
                } else {
                    $dataPoints_wh[] = null;
                }
                if (count($t_res)) {
                    $temp_measured = true;
                    $dataPoints_t[] = round(array_sum($t_res) / count($t_res));
                } else {
                    $dataPoints_t[] = null;
                }
            }
        }
    }
    $wh = round($power_stats['wh']);
    $power_stats['first'] = str_pad($power_stats['first']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['first']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['first']['s'], 2, 0, STR_PAD_LEFT);
    $power_stats['last'] = str_pad($power_stats['last']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['last']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['last']['s'], 2, 0, STR_PAD_LEFT);
    $power_stats['peak']['t'] = str_pad($power_stats['peak']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['peak']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['peak']['s'], 2, 0, STR_PAD_LEFT);
    foreach($power_details as $key => $value) {
        $power_details[$key] = gmdate("H:i:s", $value);
    }
    function save_stats($file, $data) {
        global $log_file_dir, $files, $pos;
        $save = true;
        if (file_exists($log_file_dir.$file)) {
            foreach (explode("\n", file_get_contents($log_file_dir.$file)) as $line) {
                $stat_parts = explode(',', $line);
                if ($stat_parts[0] == $files[$pos]['date'] ) {
                    $line .= "\n";
                    if ($line != $data) {
                        $contents = file_get_contents($log_file_dir.$file);
                        $contents = str_replace($line, '', $contents);
                        file_put_contents($log_file_dir.$file, $contents);
                    } else {
                        $save = false;
                    }
                    break;
                }
            }
        }
        if ($save) {
            file_put_contents($log_file_dir.$file, $data, FILE_APPEND);
        }
    }
    if ($pos > 0 && $t1 == 0 && $t2 == 23) {
        save_stats('chart_stats.csv', "{$files[$pos]['date']},{$wh},{$power_stats['first']},{$power_stats['last']},{$power_stats['peak']['p']},{$power_stats['peak']['t']}\n");
        if ($power_details) {
            save_stats('chart_details_'.$power_details_resolution.'.csv', $files[$pos]['date'].','.serialize($power_details)."\n");
        }
        if (!$file_is_compressed) {
            $files[$pos]['name'] = compress_file($files[$pos]['name'], $lines);
        }
    }
    $get_fix = trim($_GET['fix']);
    $fix_axis_y = is_numeric($get_fix) && $get_fix >= 0 ? (int)$get_fix : $fix_axis_y;
    if ($fix_axis_y) {
        $axisY_max = " max: $fix_axis_y,";
        $axisY_max_wh = " max: ".($fix_axis_y * 8).",";
    }
    echo '<title>'.$date.' ('.$produce_consume.': '.$wh.' Wh)</title><script src="chart.min.js"></script><script src="chart_keydown.js"></script><script src="swipe.js"></script>';
    $params = '&res='.$res.'&fix='.$fix_axis_y.'&t1='.$t1.'&t2='.$t2;
    if ($_GET['refresh']) {
        echo '<meta http-equiv="refresh" content="'.($res == -1 && $refresh_rate < 60 ? $refresh_rate : 60).'" />';
        $params .= '&refresh=on';
    }
    echo '<style>a { text-decoration: none; } input,select,button { cursor: pointer; }</style></head><body><div style="width: 100%;"><div style="float: left;"><a id="home" href="?" title="Zurück zur Übersicht">🏠</a></div><div style="float: right;"><a id="download" href="chart.php?file='.$files[$pos]['date'].'&download" title="Daten herunterladen">💾</a></div><div style="text-align: center;">';
    echo '';
    if ($pos < count($files)-1) {
        echo '<a id="prev" href="?file='.$files[$pos+1]['date'].$params.'" title="vorheriger Tag">⏪</a>';
    } else {
        echo '<span style="opacity: 0.3;">⏪</span>';
    }
    echo " $date ";
    if ($pos > 0) {
        echo '<a id="next" href="?file='.$files[$pos-1]['date'].$params.'" title="nächster Tag">⏩</a>';
    } else {
        echo '<span style="opacity: 0.3;">⏩</span>';
    }
    echo '</div></div>';
    if ($display_temp && $temp_measured) {
        $min = min($dataPoints_t) - 1;
        $max = max($dataPoints_t) + 1;
        $t_dataset = ",{
                label: 'Temperatur',
                yAxisID: 'y_t',
                data: ".json_encode($dataPoints_t, JSON_NUMERIC_CHECK).",
                fill: false,
                borderWidth: 2,
                borderColor: [ 'rgba(200, 100, 0, 0.5)' ],
            }";
        $t_tooltip = "else if (context.datasetIndex === 2) { return context.parsed.y + ' °C'; }";
        $t_scale = "y_t: { position: 'left', suggestedMin: $min, suggestedMax: $max, ticks: { callback: function(value, index, values) { return value + ' °C'; } } },";
    }
    echo "<div id=\"chartContainer\" style=\"height: 90%; width: 100%;\"><canvas id=\"myChart\"></canvas></div>
    <script>
    var ctx = document.getElementById('myChart');
    var myChart = new Chart(ctx, {
        type: 'line',
        data: {
            datasets: [{
                label: 'Leistung',
                yAxisID: 'y_p',
                data: ".json_encode($dataPoints, JSON_NUMERIC_CHECK).",
                fill: true,
                borderWidth: 2,
                backgroundColor: [ 'rgba(109, 120, 173, 0.7)' ],
                borderColor: [ 'rgba(109, 120, 173, 1)' ],
            },{
                label: '$produce_consume',
                yAxisID: 'y_wh',
                data: ".json_encode($dataPoints_wh, JSON_NUMERIC_CHECK).",
                fill: true,
                borderWidth: 2,
            }$t_dataset]
        },
        options: {
            plugins: {
                legend: { display: true },
                tooltip: { callbacks: { label: function(context) { if (context.datasetIndex === 0) { return context.parsed.y + ' W'; } else if (context.datasetIndex === 1) { return context.parsed.y + ' Wh'; } $t_tooltip } } }
            },
            scales: { 
                y_p: { position: 'right', suggestedMin: 0,$axisY_max ticks: { callback: function(value, index, values) { return value + ' W'; } } },
                y_wh: { display: false, suggestedMin: 0,$axisY_max_wh },
                $t_scale
            },
            elements: { point: { radius: 0, hitRadius: 50 } },
            maintainAspectRatio: false,
            animation: false,
            normalized: true,
        }
    });

    window.addEventListener('swap', function(event) {
        if (event.detail.direction == 'left') {
            location.href = document.getElementById('next').href;
        }
        if (event.detail.direction == 'right') {
            location.href = document.getElementById('prev').href;
        }
        document.body.style.opacity = '0.3';
    }, false);
    </script>";
    echo '<form method="get" style="display: inline;"><input type="hidden" name="file" value="'.$_GET['file'].'" />'.$produce_consume.': '.$wh.' Wh von '.$power_stats['first'].' bis '.$power_stats['last'].' | Peak: '.$power_stats['peak']['p'].' W um '.$power_stats['peak']['t'].' | Messwerte zusammenfassen: <select name="res" onchange="form.submit();">';
    foreach (array('-1', '1', '2', '3', '4', '5', '6', '10', '15', '20', '30', '60') as $value) {
        $selected = $value == $res ? ' selected="selected"' : '';
        if ($value == -1) {
            $text = 'Alle Messwerte anzeigen';
        } elseif ($value == 1) {
            $text = '1 Minute';
        } else {
            $text = $value.' Minuten';
        }
        echo "<option value=\"$value\"$selected>$text</option>";
    }
    echo '</select>';
    echo ' | Skala fixieren auf <input type="text" name="fix" value="'.$fix_axis_y.'" size="4" onfocusout="form.submit();" /> W (0 = dynamisch)';
    echo ' | Zeitraum eingrenzen: von <select name="t1" onchange="form.submit();">';
    for ($i = 0; $i < 24; $i++) {
        $selected = $i == $t1 ? ' selected="selected"' : '';
        $i_str = $i < 10 ? '0'.$i.':00' : $i.':00';
        echo "<option value=\"$i\"$selected>$i_str</option>";
    }
    echo '</select> bis <select name="t2" onchange="form.submit();">';
    for ($i = 0; $i < 24; $i++) {
        $selected = $i == $t2 ? ' selected="selected"' : '';
        $i_str = $i < 10 ? '0'.$i.':59' : $i.':59';
        echo "<option value=\"$i\"$selected>$i_str</option>";
    }
    echo '</select>';
    if ($pos === 0) {
        $checked = $_GET['refresh'] ? ' checked="checked"' : '';
        echo ' | <input id="refresh" type="checkbox" name="refresh" onchange="form.submit();"'.$checked.' /><label for="refresh">Grafik aktualisieren</label></form>';
        echo ' | <button onclick="location.href=\'?file='.$files[$pos]['date'].'&follow\'">#follow</button>';
    } else {
        echo '</form>';
    }
    echo ' | <button id="max" onclick="location.href=\'?file='.$files[$pos]['date'].'&max\'">#max</button>';
    echo ' | <button id="reset" onclick="location.href=\'?file='.$files[$pos]['date'].'\'">Reset</button>';
    if ($power_details_resolution) {
        echo '<style>.cell { border: 1px solid black; padding: 2px; margin:-1px 0 0 -1px; } .head { text-align: center; font-weight: bold; }</style>';
        echo '<p></p><div style="float: left; padding-bottom: 2px;"><div class="cell head">Leistung:</div><div class="cell">Dauer:</div><div class="cell">Ertrag:</div></div>';
        foreach ($power_details as $key => $value) {
            echo '<div style="float: left; padding-bottom: 2px;"><div class="cell head">&gt;'.($key ? '=' : '').' '.$key.' W</div><div class="cell">'.$value.'</div><div class="cell">'.round($power_details_wh[$key]).' Wh</div></div>';
        }
   }
    echo '</body></html>';
}
//EOF
