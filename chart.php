<?php

require('config.inc.php');

$i = 0;
$files = array();
foreach (scandir($log_file_dir, SCANDIR_SORT_DESCENDING) as $file) {
    if ($file == '.' || $file == '..' || $file == 'stats.txt' || $file == 'chart_stats.csv') {
        continue;
    }
    if (isset($_GET['file']) && $file == $_GET['file'].'.csv') {
        $pos = $i;
    }
    $i++;
    $files[] = substr($file, 0, -4);
}

if (isset($_GET['today'])) {
    header("Location: chart.php?file={$files[0]}");
}

if (isset($_GET['yesterday'])) {
    header("Location: chart.php?file={$files[1]}");
}

echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

if (!isset($_GET['file'])) {
    foreach (explode("\n", file_get_contents($log_file_dir.'chart_stats.csv')) as $line) {
        $stat_parts = explode(',', $line);
        $chart_stats[$stat_parts[0]] = $stat_parts;
    }
    echo '<title>'.$produce_consume.'sübersicht</title><style>table, th, td { border: 1px solid black; border-collapse: collapse; padding: 3px; } td.v { text-align: right; }</style></head><body><a href=".">Zurück zur aktuellen Leistungsanzeige</a><br /><br /><table border="1">';
    echo '<tr><th>Datum</th><th>'.$produce_consume.'<br />(Wh)</th><th>von</th><th>bis</th><th>Peak<br />(W)</th><th>um</th>';
    foreach ($files as $key => $file) {
        echo "<tr><td><a href=\"?file=$file\">$file</a></td><td class=\"v\">{$chart_stats[$file][1]}</td><td>{$chart_stats[$file][2]}</td><td>{$chart_stats[$file][3]}</td><td class=\"v\">{$chart_stats[$file][4]}</td><td>{$chart_stats[$file][5]}</td></tr>";
    }
    echo '</table></body></html>';
} else {
    function power_stats($value) {
        global $power_stats;
        if (!$power_stats['first'] && $value['p']) {
            $power_stats['first'] = $value;
        }
        if ($value['p']) {
            $power_stats['last'] = $value;
        }
        if ($value['p'] && $value['p'] > $power_stats['peak']['p']) {
            $power_stats['peak'] = $value;
        }
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
    $lines = explode("\n", file_get_contents($log_file_dir.$files[$pos].'.csv'));
    $date = substr($lines[0], 0, 10);
    $wh = 0;
    $dataPoints = array();
    $dataPoints_t = array();
    $dataPoints_wh = array();
    $power_stats = array('first' => array(), 'last' => array(), 'peak' => array('p' => 0));
    $temp_measured = false;
    $data = array();
    foreach ($lines as $line) {
        $data_this = explode(",", $line);
        $time_parts = explode(":", $data_this[1]);
        if ($display_temp && isset($data_this[3])) {
            $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2], 't' => $data_this[3]);
        } else {
            $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2]);
        }
    }
    if ($res == -1) {
        $last_p = 0;
        $last_timestamp = 0;
        foreach ($data as $value) {
            if ($value['h'] >= $t1 && $value['h'] <= $t2) {
                if ($last_p) {
                    $now = mktime($value['h'], $value['m'], $value['s']);
                    if ($now - $last_timestamp < 100) {
                        // only calculate if the values are not too far apart in time
                        $wh += $last_p * ($now - $last_timestamp) / 60 / 60;
                    }
                }
                $dataPoints[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => $value['p']);
                $dataPoints_wh[] = round($wh);
                if (isset($value['t'])) {
                    $temp_measured = true;
                    $dataPoints_t[] = $value['t'];
                }
                $last_p = $value['p'];
                $last_timestamp = mktime($value['h'], $value['m'], $value['s']);
                power_stats($value);
            }
        }
        if (isset($_GET['max'])) {
            header("Location: chart.php?file={$files[$pos]}&res=-1&fix=0&t1={$power_stats['first']['h']}&t2={$power_stats['last']['h']}");
        }
        if (isset($_GET['follow'])) {
            header("Location: chart.php?file={$files[$pos]}&res=-1&fix=0&t1={$power_stats['first']['h']}&t2=23&refresh=on");
        }
    } else {
        for ($h = $t1; $h <= $t2; $h++) {
            for ($m = 0; $m < 60; $m = $m + $res) {
                $p_res = array();
                $p_m = array();
                $t_res = array();
                $y = NULL;
                foreach ($data as $value) {
                    if ($value['h'] == $h && ($value['m'] >= $m && $value['m'] < $m + $res)) {
                        $p_res[] = $value['p'];
                        if ($res != 1) {
                            $p_m[$value['m']][] = $value['p'];
                        }
                        if (isset($value['t'])) {
                            $t_res[] = $value['t'];
                        }
                        power_stats($value);
                    }
                }
                if (count($p_res)) {
                    $sum = array_sum($p_res);
                    $count = count($p_res);
                    $y = round($sum / $count);
                    if ($res == 1) {
                        $wh += $sum / $count / 60;
                    } else {
                        foreach ($p_m as $p_array) {
                            $wh += array_sum($p_array) / count($p_array) / 60;
                        }
                    }
                }
                $dataPoints[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => $y);
                if (count($p_res)) {
                    $dataPoints_wh[] = round($wh);
                } else {
                    $dataPoints_wh[] = NULL;
                }
                if (count($t_res)) {
                    $temp_measured = true;
                    $dataPoints_t[] = round(array_sum($t_res) / count($t_res));
                } else {
                    $dataPoints_t[] = NULL;
                }
            }
        }
    }
    $wh = round($wh);
    $power_stats['first'] = str_pad($power_stats['first']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['first']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['first']['s'], 2, 0, STR_PAD_LEFT);
    $power_stats['last'] = str_pad($power_stats['last']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['last']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['last']['s'], 2, 0, STR_PAD_LEFT);
    $power_stats['peak']['t'] = str_pad($power_stats['peak']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['peak']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['peak']['s'], 2, 0, STR_PAD_LEFT);
    if ($pos > 0 && $t1 == 0 && $t2 == 23) {
        $save = true;
        foreach (explode("\n", file_get_contents($log_file_dir.'chart_stats.csv')) as $line) {
            $stat_parts = explode(',', $line);
            if ($stat_parts[0] == $files[$pos]) {
                $save = false;
                break;
            }
        }
        if ($save) {
            file_put_contents($log_file_dir.'chart_stats.csv', "{$files[$pos]},{$wh},{$power_stats['first']},{$power_stats['last']},{$power_stats['peak']['p']},{$power_stats['peak']['t']}\n", FILE_APPEND);
        }
    }
    $get_fix = trim($_GET['fix']);
    $fix_axis_y = is_numeric($get_fix) && $get_fix >= 0 ? (int)$get_fix : $fix_axis_y;
    if ($fix_axis_y) {
        $axisY_max = " max: $fix_axis_y,";
        $axisY_max_wh = " max: ".($fix_axis_y * 6).",";
    }
    echo '<title>'.$date.' ('.$produce_consume.': '.$wh.' Wh)</title><script src="chart.min.js"></script>';
    echo '<script>document.onkeydown = function(e) { if (!e) { e = window.event; } if (e.which) { kcode = e.which; } else if (e.keyCode) { kcode = e.keyCode; } if (kcode == 33) { document.getElementById("next").click(); } if (kcode == 34) { document.getElementById("prev").click(); } };</script>';
    $params = '&res='.$res.'&fix='.$fix_axis_y.'&t1='.$t1.'&t2='.$t2;
    if ($_GET['refresh']) {
        echo '<meta http-equiv="refresh" content="'.($res == -1 && $refresh_rate < 60 ? $refresh_rate : 60).'" />';
        $params .= '&refresh=on';
    }
    echo '</head><body><a href="?">Zurück zur Übersicht</a>';
    echo '<div style="width: 100%; text-align: center">';
    if ($pos < count($files)-1) {
        echo '<button onclick="location.href=this.children[0].href" style="cursor: pointer"><a id="prev" href="?file='.$files[$pos+1].$params.'">&laquo;</a></button> ';
    } else {
        echo '&laquo';
    }
    echo " <a href=\"{$log_file_dir}{$files[$pos]}.csv\" title=\"Daten Herunterladen\">{$date}</a> ";
    if ($pos > 0) {
        echo '<button onclick="location.href=this.children[0].href" style="cursor: pointer"><a id="next" href="?file='.$files[$pos-1].$params.'">&raquo;</a></button>';
    } else {
        echo '&raquo;';
    }
    echo '</div>';
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
    </script>";
    echo '<form method="get"><input type="hidden" name="file" value="'.$_GET['file'].'" />'.$produce_consume.': '.$wh.' Wh von '.$power_stats['first'].' bis '.$power_stats['last'].' | Peak: '.$power_stats['peak']['p'].' W um '.$power_stats['peak']['t'].' | Messwerte zusammenfassen: <select name="res" onchange="form.submit();">';
    foreach (array('-1', '1', '5', '10', '15', '20', '30', '60') as $value) {
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
        echo ' | <input id="refresh" type="checkbox" name="refresh" onchange="form.submit();"'.$checked.' /><label for="refresh">Grafik aktualisieren</label>';
        echo ' | <button onclick="location.href=this.children[0].href" style="cursor: pointer"><a href="?file='.$files[$pos].'&follow">#follow</a></button>';
    }
    echo ' | <button onclick="location.href=this.children[0].href" style="cursor: pointer"><a href="?file='.$files[$pos].'&max">#max</a></button>';
    echo ' | <button onclick="location.href=this.children[0].href" style="cursor: pointer"><a href="?file='.$files[$pos].'">Reset</a></button>';
    echo '</form></body></html>';
}
//EOF