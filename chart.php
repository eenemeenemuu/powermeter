<?php

require('config.inc.php');
require('functions.inc.php');

if ($_GET['file'] || isset($_GET['today']) || isset($_GET['yesterday'])) {
    list($files, $pos) = pm_scan_log_file_dir();

    if (isset($_GET['today'])) {
        header("Location: chart.php?file={$files[0]['date']}");
    }

    if (isset($_GET['yesterday'])) {
        header("Location: chart.php?file={$files[1]['date']}");
    }

    echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

    if ($pos === false) {
        die('Error! File not found: '.htmlentities($_GET['file']));
    }
    function power_stats($value) {
        global $power_stats, $power_details, $power_details_wh, $power_details_resolution, $device;
        $value['p'] = floatval($value['p']);
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
                        $power_details_wh[$i] += ($power_stats['last_p'] - $i) * ($now - $power_stats['last_timestamp']) / 60 / 60;
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
    if (isset($_GET['max'])) {
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
    $data = trim($data);
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
    sort($lines);
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
    if ($_GET['refresh'] && $pos === 0) {
        $meta_refresh = '<meta http-equiv="refresh" content="'.($res == -1 && $refresh_rate < 60 ? $refresh_rate : 60).'" />';
        $data_last_h = intval($data[array_key_last($data)]['h']);
        if ($t2 < $data_last_h) {
            $t2 = $data_last_h;
        }
    }
    if ($res == -1) {
        foreach ($data as $value) {
            if ($value['h'] >= $t1 && $value['h'] <= $t2) {
                power_stats($value);
                $dataPoints[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => pm_round($value['p']));
                $dataPoints_wh[] = pm_round($power_stats['wh']);
                if (isset($value['t'])) {
                    $temp_measured = true;
                    $dataPoints_t[] = pm_round($value['t']);
                }
            }
        }
        if (isset($_GET['max'])) {
            header("Location: chart.php?file={$files[$pos]['date']}&res=-1&fix=0&t1={$power_stats['first']['h']}&t2={$power_stats['last']['h']}".(isset($_GET['refresh']) ? '&refresh=on' : ''));
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
                    $y = pm_round(array_sum($p_res) / count($p_res));
                }
                $dataPoints[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => $y);
                if (count($p_res)) {
                    $dataPoints_wh[] = pm_round($power_stats['wh']);
                } else {
                    $dataPoints_wh[] = null;
                }
                if (count($t_res)) {
                    $temp_measured = true;
                    $dataPoints_t[] = pm_round(array_sum($t_res) / count($t_res));
                } else {
                    $dataPoints_t[] = null;
                }
            }
        }
    }
    $wh = pm_round($power_stats['wh'], true);
    $power_stats['first'] = str_pad($power_stats['first']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['first']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['first']['s'], 2, 0, STR_PAD_LEFT);
    $power_stats['last'] = str_pad($power_stats['last']['h'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['last']['m'], 2, 0, STR_PAD_LEFT).':'.str_pad($power_stats['last']['s'], 2, 0, STR_PAD_LEFT);
    $power_stats['peak']['p'] = pm_round($power_stats['peak']['p'], true);
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
    echo '<title>'.$date.' ('.$produce_consume.': '.$wh.' Wh)</title><script src="js/chart.min.js"></script><script src="js/chart_keydown.js"></script><script src="js/swipe.js"></script>'.$meta_refresh;
    $params = '&res='.$res.'&fix='.$fix_axis_y.'&t1='.$t1.'&t2='.$t2;
    echo '<style>a { text-decoration: none; } input,select,button { cursor: pointer; }</style></head><body><div style="width: 100%;"><div style="float: left;"><a id="home" href="overview.php" title="Zur '.$produce_consume.'sübersicht">📋</a> <a href="index.php" title="Zur aktuellen Leistungsanzeige">🔌</a></div><div style="float: right;"><a id="download" href="chart.php?file='.$files[$pos]['date'].'&download" title="Daten herunterladen">💾</a></div><div style="text-align: center;">';
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
        $dataPoints_t_wo_null = array_diff($dataPoints_t, array(null));
        $min = ceil(min($dataPoints_t_wo_null)) - 1;
        $max = floor(max($dataPoints_t_wo_null)) + 1;
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
            elements: { point: { radius: 0, hitRadius: 10 } },
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
    echo '<form method="get" style="display: inline;"><input type="hidden" name="file" value="'.$_GET['file'].'" />'.$produce_consume.': '.$wh.' Wh von '.$power_stats['first'].' bis '.$power_stats['last'].' | Peak: '.$power_stats['peak']['p'].' W um '.$power_stats['peak']['t'].' | Messwerte zusammenfassen: <select id="res" name="res" onchange="this.form.submit();">';
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
    echo ' | Skala fixieren auf <input type="text" id="fix" name="fix" value="'.$fix_axis_y.'" size="4" onfocusout="form.submit();" /> W (0 = dynamisch)';
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
    } else {
        echo '</form>';
    }
    echo ' | <button id="max" onclick="location.href=\'?file='.$files[$pos]['date'].'&max'.($_GET['refresh'] ? '&refresh' : '').'\'">#max</button>';
    echo ' | <button id="reset" onclick="location.href=\'?file='.$files[$pos]['date'].'\'">Reset</button>';
    if ($power_details_resolution) {
        $key_last = false;
        $power_details_wh2 = $power_details_wh;
        foreach ($power_details_wh as $key => $value) {
            if ($key_last !== false) {
                $power_details_wh2[$key_last] -= $value;
            }
            $key_last = $key;
        }
        $power_details_wh3_sum = 0;
        $power_details_wh3 = array();
        foreach ($power_details_wh2 as $key => $value) {
            $power_details_wh3_sum += $value;
            $power_details_wh3[$key] = $power_details_wh3_sum;
        }
        echo '<style>.cell { border: 1px solid black; padding: 2px; margin:-1px 0 0 -1px; } .head { text-align: center; font-weight: bold; }</style>';
        echo '<p></p><div style="float: left; padding-bottom: 2px;"><div class="cell head">Leistung:</div><div class="cell">Dauer:</div><div class="cell">'.$produce_consume.':</div><div class="cell head">Leistung:</div><div class="cell">'.$produce_consume.':</div><div class="cell head">Leistung:</div><div class="cell">'.$produce_consume.':</div></div>';
        foreach ($power_details as $key => $value) {
            echo '<div style="float: left; padding-bottom: 2px;"><div class="cell head">'.($key ? '&ge;' : '&gt;').' '.$key.' W</div><div class="cell">'.$value.'</div><div class="cell">'.pm_round($power_details_wh[$key], true).' Wh</div><div class="cell head">'.($key ? $key : $key+1).' - '.($key+$power_details_resolution-1).' W</div><div class="cell">'.pm_round($power_details_wh2[$key], true).' Wh</div><div class="cell head">&lt; '.($key+$power_details_resolution).' W</div><div class="cell">'.pm_round($power_details_wh3[$key], true).' Wh</div></div>';
        }
    }
    echo '</body></html>';
} elseif ($_GET['m']) {
    $month = htmlentities(trim($_GET['m']));
    list($chart_stats) = pm_scan_chart_stats();

    foreach ($chart_stats as $day => $data) {
        $this_month = substr($day, 0, 7);
        $chart_stats_months[] = $this_month;
        if ($this_month == $month) {
            $chart_stats_this_month[$day] = $data[1];
        }
    }
    $chart_stats_months = array_unique($chart_stats_months);
    rsort($chart_stats_months);
    ksort($chart_stats_this_month);

    $pos = false;
    foreach ($chart_stats_months as $key => $value) {
        if ($value == $month) {
            $pos = $key;
            break;
        }
    }

    echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

    if ($pos === false) {
        die('Error! No data found for '.$month);
    }
    $kwh = pm_round(array_sum($chart_stats_this_month)/1000);
    if (isset($_GET['download'])) {
        header('Content-type: text/csv');
        header('Content-Disposition: attachment; filename="'.$month.'.csv"');
        ob_clean();
        flush();
        foreach ($chart_stats_this_month as $key => $value) {
            echo "$key,$value\n";
        }
        die();
    }
    $get_fix = trim($_GET['fix']);
    $fix_axis_y = is_numeric($get_fix) && $get_fix >= 0 ? intval($get_fix) : round($fix_axis_y * 8 / 100) * 100;
    if ($fix_axis_y) {
        $axisY_max = " max: $fix_axis_y,";
    }
    $params = '&fix='.$fix_axis_y;
    echo '<title>'.$month.' ('.$produce_consume.': '.$kwh.' kWh)</title><script src="js/chart.min.js"></script><script src="js/chart_keydown.js"></script><script src="js/swipe.js"></script>';
    echo '<style>a { text-decoration: none; }</style></head><body><div style="width: 100%;"><div style="float: left;"><a id="home" href="overview.php" title="Zur '.$produce_consume.'sübersicht">📋</a> <a href="index.php" title="Zur aktuellen Leistungsanzeige">🔌</a></div><div style="float: right;"><a id="download" href="chart.php?m='.$month.'&download" title="Daten herunterladen">💾</a></div><div style="text-align: center;">';
    echo '';
    if ($pos < count($chart_stats_months)-1) {
        echo '<a id="prev" href="?m='.$chart_stats_months[$pos+1].$params.'" title="vorheriger Monat">⏪</a>';
    } else {
        echo '<span style="opacity: 0.3;">⏪</span>';
    }
    echo " $month ";
    if ($pos > 0) {
        echo '<a id="next" href="?m='.$chart_stats_months[$pos-1].$params.'" title="nächster Monat">⏩</a>';
    } else {
        echo '<span style="opacity: 0.3;">⏩</span>';
    }
    echo '</div></div>';
    echo "<div id=\"chartContainer\" style=\"height: 90%; width: 100%;\"><canvas id=\"myChart\"></canvas></div>
    <script>
    var ctx = document.getElementById('myChart');
    var myChart = new Chart(ctx, {
        type: 'bar',
        data: {
            datasets: [{
                label: '$produce_consume',
                yAxisID: 'y',
                data: ".json_encode($chart_stats_this_month, JSON_NUMERIC_CHECK).",
                fill: true,
                borderWidth: 2,
                backgroundColor: [ 'rgba(109, 120, 173, 0.7)' ],
                borderColor: [ 'rgba(109, 120, 173, 1)' ],
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(context) { return context.parsed.y + ' Wh'; } } }
            },
            scales: { 
                y: { position: 'right', suggestedMin: 0,$axisY_max ticks: { callback: function(value, index, values) { return value + ' Wh'; } } },
            },
            elements: { point: { radius: 0, hitRadius: 10 } },
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
    echo '<form method="get" style="display: inline;"><input type="hidden" name="m" value="'.$_GET['m'].'" />'.$produce_consume.' (gesamt): '.$kwh.' kWh';
    if (count($chart_stats_this_month) > 1) {
        asort($chart_stats_this_month);
        echo ' | '.$produce_consume.' (max): '.array_pop($chart_stats_this_month).' Wh';
        echo ' | '.$produce_consume.' (min): '.array_shift($chart_stats_this_month).' Wh';
    }
    echo ' | Skala fixieren auf <input type="text" id="fix" name="fix" value="'.$fix_axis_y.'" size="7" onfocusout="form.submit();" /> Wh (0 = dynamisch)';
} elseif ($_GET['y']) {
    $year = htmlentities(trim($_GET['y']));
    list($chart_stats) = pm_scan_chart_stats();

    foreach ($chart_stats as $day => $data) {
        $this_year = substr($day, 0, 4);
        $chart_stats_years[] = $this_year;
        if ($this_year == $year) {
            $chart_stats_this_year[substr($day, 0, 7)] += $data[1]/1000;
        }
    }
    $chart_stats_years = array_unique($chart_stats_years);
    rsort($chart_stats_years);
    ksort($chart_stats_this_year);

    $pos = false;
    foreach ($chart_stats_years as $key => $value) {
        if ($value == $year) {
            $pos = $key;
            break;
        }
    }

    echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

    if ($pos === false) {
        die('Error! No data found for '.$year);
    }
    $kwh = pm_round(array_sum($chart_stats_this_year));
    foreach ($chart_stats_this_year as $key => $value) {
        $chart_stats_this_year[$key] = pm_round($value);
    }
    if (isset($_GET['download'])) {
        header('Content-type: text/csv');
        header('Content-Disposition: attachment; filename="'.$year.'.csv"');
        ob_clean();
        flush();
        foreach ($chart_stats_this_year as $key => $value) {
            echo "$key,$value\n";
        }
        die();
    }
    $get_fix = trim($_GET['fix']);
    $fix_axis_y = is_numeric($get_fix) && $get_fix >= 0 ? intval($get_fix) : round($fix_axis_y / 50) * 10;
    if ($fix_axis_y) {
        $axisY_max = " max: $fix_axis_y,";
    }
    $params = '&fix='.$fix_axis_y;
    echo '<title>'.$year.' ('.$produce_consume.': '.$kwh.' kWh)</title><script src="js/chart.min.js"></script><script src="js/chart_keydown.js"></script><script src="js/swipe.js"></script>';
    echo '<style>a { text-decoration: none; }</style></head><body><div style="width: 100%;"><div style="float: left;"><a id="home" href="overview.php" title="Zur '.$produce_consume.'sübersicht">📋</a> <a href="index.php" title="Zur aktuellen Leistungsanzeige">🔌</a></div><div style="float: right;"><a id="download" href="chart.php?m='.$month.'&download" title="Daten herunterladen">💾</a></div><div style="text-align: center;">';
    echo '';
    if ($pos < count($chart_stats_years)-1) {
        echo '<a id="prev" href="?y='.$chart_stats_years[$pos+1].$params.'" title="vorheriger Monat">⏪</a>';
    } else {
        echo '<span style="opacity: 0.3;">⏪</span>';
    }
    echo " $year ";
    if ($pos > 0) {
        echo '<a id="next" href="?y='.$chart_stats_years[$pos-1].$params.'" title="nächster Monat">⏩</a>';
    } else {
        echo '<span style="opacity: 0.3;">⏩</span>';
    }
    echo '</div></div>';
    echo "<div id=\"chartContainer\" style=\"height: 90%; width: 100%;\"><canvas id=\"myChart\"></canvas></div>
    <script>
    var ctx = document.getElementById('myChart');
    var myChart = new Chart(ctx, {
        type: 'bar',
        data: {
            datasets: [{
                label: '$produce_consume',
                yAxisID: 'y',
                data: ".json_encode($chart_stats_this_year, JSON_NUMERIC_CHECK).",
                fill: true,
                borderWidth: 2,
                backgroundColor: [ 'rgba(109, 120, 173, 0.7)' ],
                borderColor: [ 'rgba(109, 120, 173, 1)' ],
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(context) { return context.parsed.y + ' kWh'; } } }
            },
            scales: { 
                y: { position: 'right', suggestedMin: 0,$axisY_max ticks: { callback: function(value, index, values) { return value + ' kWh'; } } },
            },
            elements: { point: { radius: 0, hitRadius: 10 } },
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
    echo '<form method="get" style="display: inline;"><input type="hidden" name="y" value="'.$_GET['y'].'" />'.$produce_consume.' (gesamt): '.$kwh.' kWh';
    if (count($chart_stats_this_year) > 1) {
        asort($chart_stats_this_year);
        echo ' | '.$produce_consume.' (max): '.array_pop($chart_stats_this_year).' kWh';
        echo ' | '.$produce_consume.' (min): '.array_shift($chart_stats_this_year).' kWh';
    }
    echo ' | Skala fixieren auf <input type="text" id="fix" name="fix" value="'.$fix_axis_y.'" size="4" onfocusout="form.submit();" /> kWh (0 = dynamisch)';
} else {
    header("Location: overview.php");
}

//EOF
