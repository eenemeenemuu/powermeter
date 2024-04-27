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

    if ($pos === false) {
        die('Error! File not found: '.htmlentities($_GET['file']));
    }
    function power_stats($value) {
        global $power_stats, $power_details, $power_details_wh, $power_details_resolution, $device, $unit2;
        $value['p'] = floatval($value['p']);
        if (!$power_stats['first'] && $value['p']) {
            $power_stats['first'] = $value;
        }
        if ($value['p']) {
            $power_stats['last'] = $value;
        }
        $value_abs = $value;
        $value_abs['p'] = abs($value['p']);
        if ($value_abs['p'] && $value_abs['p'] > $power_stats['peak']['p']) {
            $power_stats['peak'] = $value_abs;
        }
        if ($unit2 == '%') {
            $power_stats['percent_min'] = min($power_stats['percent_min'], $value['t']);
            $power_stats['percent_max'] = max($power_stats['percent_max'], $value['t']);
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
                if ($power_stats['last_p'] < 0) {
                    $power_stats['wh_feed'] -= $power_stats['last_p'] * ($now - $power_stats['last_timestamp']) / 60 / 60;
                } else {
                    $power_stats['wh'] += $power_stats['last_p'] * ($now - $power_stats['last_timestamp']) / 60 / 60;
                }
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
    function get_y_min_max($min_max, $data) {
        $multiplier = 1;
        while (!$floor_ceil) {
            foreach ([1, 2, 5] as $i) {
                if (abs($data) < $i * $multiplier) {
                    $floor_ceil = $i * $multiplier / 10;
                    break;
                }
            }
            $multiplier *= 10;
        }
        if ($min_max == 'max') {
            return " max: ".(ceil($data/$floor_ceil)*$floor_ceil).",";
        }
        if ($min_max == 'min') {
            return " min: ".(floor($data/$floor_ceil)*$floor_ceil).",";
        }
        return false;
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
    $dataPoints_wh_feed = array();
    $dataPoints_feed = array();
    $dataPoints_y_max = 0;
    $power_stats = array('first' => array(), 'last' => array(), 'peak' => array('p' => 0), 'wh' => 0);
    if ($unit2 == '%') {
        $power_stats['percent_min'] = PHP_INT_MAX;
        $power_stats['percent_max'] = PHP_INT_MIN;
    }
    $power_details = array();
    $power_details_wh = array();
    $unit2_measured = false;
    $feed_measured = false;
    $extra_data = false;
    $data = array();
    sort($lines);
    foreach ($lines as $line) {
        if (trim($line)) {
            $data_this = explode(",", $line);
            $time_parts = explode(":", $data_this[1]);
            if ($_GET['3p']) {
                $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2], 'l1' => $data_this[4], 'l2' => $data_this[5], 'l3' => $data_this[6]);
            } elseif ($unit2_display && isset($data_this[3])) {
                $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2], 't' => $data_this[3]);
            } else {
                $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2]);
            }
            if (!$extra_data && isset($data_this[4])) {
                $extra_data = true;
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
                if ($_GET['3p']) {
                    $dataPoints_l1[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => pm_round($value['l1']));
                    $dataPoints_l2[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => pm_round($value['l2']));
                    $dataPoints_l3[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => pm_round($value['l3']));
                } else {
                    power_stats($value);
                    if ($value['p'] < 0) {
                        $feed_measured = true;
                        $dataPoints[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => 0);
                        $dataPoints_feed[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => pm_round(abs($value['p'])));
                    } else {
                        $dataPoints[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => pm_round($value['p']));
                        $dataPoints_feed[] = array("x" => $value['h'].':'.$value['m'].':'.$value['s'], "y" => 0);
                    }
                    if (abs($value['p']) > $dataPoints_y_max) {
                        $dataPoints_y_max = abs($value['p']);
                    }
                    $dataPoints_wh[] = pm_round($power_stats['wh']);
                    $dataPoints_wh_feed[] = pm_round($power_stats['wh_feed']);
                    if (isset($value['t'])) {
                        $unit2_measured = true;
                        $dataPoints_t[] = pm_round($value['t']);
                    }
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
                $l1_res = array();
                $l2_res = array();
                $l3_res = array();
                $p_res = array();
                $t_res = array();
                $p_res_feed = array();
                $y = 0;
                $y_feed = 0;
                foreach ($data as $value) {
                    if ($value['h'] == $h && ($value['m'] >= $m && $value['m'] < $m + $res)) {
                        if ($_GET['3p']) {
                            $l1_res[] = $value['l1'];
                            $l2_res[] = $value['l2'];
                            $l3_res[] = $value['l3'];
                        } else {
                            if ($value['p'] < 0) {
                                $feed_measured = true;
                                $p_res_feed[] = $value['p'];
                            } else {
                                $p_res[] = $value['p'];
                            }
                            if (isset($value['t'])) {
                                $t_res[] = $value['t'];
                            }
                            power_stats($value);
                        }
                    }
                }
                if ($_GET['3p']) {
                    $dataPoints_l1[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => count($l1_res) ? pm_round(array_sum($l1_res) / count($l1_res)) : 0);
                    $dataPoints_l2[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => count($l2_res) ? pm_round(array_sum($l2_res) / count($l2_res)) : 0);
                    $dataPoints_l3[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => count($l3_res) ? pm_round(array_sum($l3_res) / count($l3_res)) : 0);
                } else {
                    if (count($p_res)) {
                        $y = array_sum($p_res) / count($p_res);
                        if ($y > $dataPoints_y_max) {
                            $dataPoints_y_max = $y;
                        }
                    }
                    $dataPoints[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => pm_round($y));

                    if (count($p_res_feed)) {
                        $feed_measured = true;
                        $y_feed = abs(array_sum($p_res_feed) / count($p_res_feed));
                        if ($y_feed > $dataPoints_y_max) {
                            $dataPoints_y_max = $y_feed;
                        }
                    }
                    $dataPoints_feed[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => pm_round($y_feed));

                    $dataPoints_wh[] = pm_round($power_stats['wh']);
                    $dataPoints_wh_feed[] = pm_round($power_stats['wh_feed']);

                    if (count($t_res)) {
                        $unit2_measured = true;
                        $dataPoints_t[] = pm_round(array_sum($t_res) / count($t_res));
                    } else {
                        $dataPoints_t[] = null;
                    }
                }
            }
        }
    }
    if (isset($_GET['percent_max'])) {
        echo $power_stats['percent_max'];
        die();
    }
    if (isset($_GET['power_max'])) {
        echo $power_stats['peak']['p'];
        die();
    }
    if (isset($_GET['wh'])) {
        echo $power_stats['wh'];
        die();
    }
    $wh = pm_round($power_stats['wh'], true);
    $wh_feed = pm_round($power_stats['wh_feed'], true);
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
                if ($stat_parts[0] == $files[$pos]['date']) {
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
    if ($pos > 0 && $t1 == 0 && $t2 == 23 && !$_GET['3p']) {
        $stats_str = "{$files[$pos]['date']},{$wh},{$power_stats['first']},{$power_stats['last']},{$power_stats['peak']['p']},{$power_stats['peak']['t']}";
        if ($power_stats['wh_feed']) {
            $stats_str .= ','.$wh_feed;
        }
        if ($unit2 == '%') {
            $stats_str .= ",{$power_stats['percent_min']},{$power_stats['percent_max']}";
        }
        save_stats('chart_stats.csv', $stats_str."\n");
        if ($power_details) {
            save_stats('chart_details_'.$power_details_resolution.'.csv', $files[$pos]['date'].','.serialize($power_details_wh)."\n");
        }
        if (!$file_is_compressed) {
            $files[$pos]['name'] = compress_file($files[$pos]['name'], $lines);
        }
    }
    $get_fix = trim($_GET['fix']);
    $fix_axis_y = is_numeric($get_fix) && $get_fix >= 0 ? (int)$get_fix : $fix_axis_y;
    if ($fix_axis_y) {
        $axisY_max = " max: $fix_axis_y,";
    } elseif ($feed_measured) {
        $axisY_max = get_y_min_max('max', $dataPoints_y_max);
    }
    echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';
    echo '<title>'.$date;
    if ($feed_measured) {
        echo ' ('.$unit1_label_in.': '.$wh.' '.$unit1.'h | '.$unit1_label_out.': '.$wh_feed.' '.$unit1.'h)';
    } elseif (!$_GET['3p']) {
        echo ' ('.$unit1_label.': '.$wh.' '.$unit1.'h)';
    }
    echo '</title><script src="js/chart.min.js"></script><script src="js/chart_keydown.js"></script><script src="js/swipe.js"></script>'.$meta_refresh;
    $params = '&res='.$res.'&fix='.$fix_axis_y.'&t1='.$t1.'&t2='.$t2.($_GET['3p'] ? '&3p=on' : '');
    echo '<style>a { text-decoration: none; } input,select,button { cursor: pointer; }</style></head><body><div style="width: 100%;"><div style="float: left;"><a id="live" href="index.php" title="Zur aktuellen Leistungsanzeige">🔌</a> <a id="overview" href="overview.php" title="Zur Übersicht">📋</a> <a id="expand" href="?m='.substr($_GET['file'], 0, 7).'" title="Zur Monatsansicht">📅</a></div><div style="float: right;"><a id="download" href="chart.php?file='.$files[$pos]['date'].'&download" title="Daten herunterladen">💾</a></div><div style="text-align: center;">';
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
    if ($_GET['3p']) {
        $axisY_min = get_y_min_max('min', min(min(array_column($dataPoints_l1, 'y')), min(array_column($dataPoints_l2, 'y')), min(array_column($dataPoints_l3, 'y'))));
        $axisY_max = get_y_min_max('max', max(max(array_column($dataPoints_l1, 'y')), max(array_column($dataPoints_l2, 'y')), max(array_column($dataPoints_l3, 'y'))));
        $dataPoints_l3_empty = true;
        foreach ($dataPoints_l3 as $value) {
            if ($value['y']) {
                $dataPoints_l3_empty = false;
                break;
            }
        }
        if ($dataPoints_l3_empty) {
            $unit5 = $unit4;
            $dataPoints_l2_empty = true;
            foreach ($dataPoints_l2 as $value) {
                if ($value['y']) {
                    $dataPoints_l2_empty = false;
                    break;
                }
            }
        }
        $datasets = "{
                    label: '".strip_tags($unit3_label)."',
                    yAxisID: 'y_l1',
                    data: ".json_encode($dataPoints_l1, JSON_NUMERIC_CHECK).",
                    fill: true,
                    borderWidth: 2,
                    backgroundColor: [ 'rgba(128, 64, 0, 0.5)' ],
                    borderColor: [ 'rgba(128, 64, 0, 1)' ],
                }";
        if (!$dataPoints_l2_empty) {
            $datasets .= ",{
                    label: '".strip_tags($unit4_label)."',
                    yAxisID: 'y_l2',
                    data: ".json_encode($dataPoints_l2, JSON_NUMERIC_CHECK).",
                    fill: true,
                    borderWidth: 2,
                    backgroundColor: [ 'rgba(0, 0, 0, 0.5)' ],
                    borderColor: [ 'rgba(0, 0, 0, 1)' ],
                }";
        } else {
            $unit4 = $unit3;
        }
        if (!$dataPoints_l3_empty) {
            $datasets .= ",{
                    label: '".strip_tags($unit5_label)."',
                    yAxisID: 'y_l3',
                    data: ".json_encode($dataPoints_l3, JSON_NUMERIC_CHECK).",
                    fill: true,
                    borderWidth: 2,
                    backgroundColor: [ 'rgba(128, 128, 128, 0.5)' ],
                    borderColor: [ 'rgba(128, 128, 128, 1)' ],
                }";
        }
        if ($unit3 === $unit4 && $unit4 === $unit5) {
            $tooltip_unit = "{ return context.parsed.y + ' $unit3'; }";
            $ticks = " ticks: { callback: function(value, index, values) { return value + ' $unit3'; } },";
        } else {
            $tooltip_unit = "{ if (context.datasetIndex === 0) { return context.parsed.y + ' $unit3'; } else if (context.datasetIndex === 1) { return context.parsed.y + ' {$unit4}'; } else if (context.datasetIndex === 2) { return context.parsed.y + ' {$unit5}'; } }";
            $ticks = "";
        }

        echo "<div id=\"chartContainer\" style=\"height: 90%; width: 100%;\"><canvas id=\"myChart\"></canvas></div>
        <script>
        var ctx = document.getElementById('myChart');
        var myChart = new Chart(ctx, {
            type: 'line',
            data: {
                datasets: [$datasets]
            },
            options: {
                plugins: {
                    legend: { display: true },
                    tooltip: { position: 'nearest', callbacks: { label: function(context) $tooltip_unit } }
                },
                scales: { 
                    y_l1: {{$axisY_min}{$axisY_max}{$ticks} position: 'right' },
                    y_l2: {{$axisY_min}{$axisY_max} display: false },
                    y_l3: {{$axisY_min}{$axisY_max} display: false },
                },
                interaction: {
                    mode: 'index',
                },
                elements: { point: { radius: 0, hitRadius: 10 } },
                maintainAspectRatio: false,
                animation: false,
                normalized: true,
            }
        });";
    } else {
        $datasetIndex = 2;
        if ($feed_measured) {
            $feed_dataset = ",{
                    label: '".strip_tags($unit1_label_out)."',
                    yAxisID: 'y_feed',
                    data: ".json_encode($dataPoints_feed, JSON_NUMERIC_CHECK).",
                    fill: true,
                    borderWidth: 2,
                    backgroundColor: [ 'rgba(127, 255, 0, 0.5)' ],
                    borderColor: [ 'rgba(127, 255, 0, 1)' ],
                }";
            $feed_tooltip = "else if (context.datasetIndex === $datasetIndex) { return context.parsed.y + ' $unit1'; } ";
            $feed_scale = "y_feed: { display: false, suggestedMin: 0,$axisY_max ticks: { callback: function(value, index, values) { return value + ' $unit1'; } } },";
            $datasetIndex++;

            $axisY_max_wh = ', max: '.ceil(max($wh, $wh_feed)/100)*100;
            $wh_feed_dataset = ",{
                    label: '".strip_tags($unit1_label_out)." (Summe)',
                    yAxisID: 'y_wh_feed',
                    data: ".json_encode($dataPoints_wh_feed, JSON_NUMERIC_CHECK).",
                    fill: true,
                    borderWidth: 2,
                    backgroundColor: [ 'rgba(127, 255, 0, 0.15)' ],
                    borderColor: [ 'rgba(127, 255, 0, 0.3)' ],
                }";
            $wh_feed_tooltip = "else if (context.datasetIndex === $datasetIndex) { return context.parsed.y + ' {$unit1}h'; } ";
            $wh_feed_scale = "y_wh_feed: { display: false, suggestedMin: 0{$axisY_max_wh}, ticks: { callback: function(value, index, values) { return value + ' {$unit1}h'; } } },";
            $datasetIndex++;
        }
        if ($unit2_display && $unit2_measured) {
            $dataPoints_t_wo_null = array_diff($dataPoints_t, array(null));
            $min = isset($unit2_min) && $unit2_min !== false ? $unit2_min : ceil(min($dataPoints_t_wo_null)) - 1;
            $max = isset($unit2_max) && $unit2_max !== false ? $unit2_max : floor(max($dataPoints_t_wo_null)) + 1;
            $t_dataset = ",{
                    label: '".strip_tags($unit2_label)."',
                    yAxisID: 'y_t',
                    data: ".json_encode($dataPoints_t, JSON_NUMERIC_CHECK).",
                    fill: false,
                    borderWidth: 2,
                    borderColor: [ 'rgba(200, 100, 0, 0.5)' ],
                }";
            $t_tooltip = "else if (context.datasetIndex === $datasetIndex) { return context.parsed.y + ' $unit2'; }";
            $t_scale = "y_t: { position: 'left', suggestedMin: $min, suggestedMax: $max, ticks: { callback: function(value, index, values) { return value + ' $unit2'; } } },";
            $datasetIndex++;
        }
        echo "<div id=\"chartContainer\" style=\"height: 90%; width: 100%;\"><canvas id=\"myChart\"></canvas></div>
        <script>
        var ctx = document.getElementById('myChart');
        var myChart = new Chart(ctx, {
            type: 'line',
            data: {
                datasets: [{
                    label: '".($feed_measured ? strip_tags($unit1_label_in) : 'Leistung')."',
                    yAxisID: 'y_p',
                    data: ".json_encode($dataPoints, JSON_NUMERIC_CHECK).",
                    fill: true,
                    borderWidth: 2,
                    backgroundColor: [ 'rgba(109, 120, 173, 0.5)' ],
                    borderColor: [ 'rgba(109, 120, 173, 1)' ],
                },{
                    label: '".($feed_measured ? strip_tags($unit1_label_in).' (Summe)' : $unit1_label)."',
                    yAxisID: 'y_wh',
                    data: ".json_encode($dataPoints_wh, JSON_NUMERIC_CHECK).",
                    fill: true,
                    borderWidth: 2,
                    backgroundColor: [ 'rgba(109, 120, 173, 0.15)' ],
                    borderColor: [ 'rgba(109, 120, 173, 0.3)' ],
                }{$feed_dataset}{$wh_feed_dataset}{$t_dataset}]
            },
            options: {
                plugins: {
                    legend: { display: true },
                    tooltip: { callbacks: { label: function(context) { if (context.datasetIndex === 0) { return context.parsed.y + ' $unit1'; } else if (context.datasetIndex === 1) { return context.parsed.y + ' {$unit1}h'; } {$feed_tooltip}{$wh_feed_tooltip}{$t_tooltip} } } }
                },
                scales: { 
                    y_p: { position: 'right', suggestedMin: 0,$axisY_max ticks: { callback: function(value, index, values) { return value + ' $unit1'; } } },
                    y_wh: { display: false, suggestedMin: 0{$axisY_max_wh} },
                    $feed_scale
                    $wh_feed_scale
                    $t_scale
                },
                elements: { point: { radius: 0, hitRadius: 10 } },
                maintainAspectRatio: false,
                animation: false,
                normalized: true,
            }
        });";
    }
    echo "
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
    echo '<form method="get" style="display: inline;"><input type="hidden" name="file" value="'.$_GET['file'].'" />';
    if (!$_GET['3p']) {
        if ($feed_measured) {
            echo $unit1_label_in.': '.$wh.' '.$unit1.'h | '.$unit1_label_out.': '.$wh_feed.' '.$unit1.'h | ';
        } else {
            echo $unit1_label.': '.$wh.' '.$unit1.'h von '.$power_stats['first'].' bis '.$power_stats['last'].' | Peak: '.$power_stats['peak']['p'].' '.$unit1.' um '.$power_stats['peak']['t'].' | ';
        }
    }
    echo 'Messwerte zusammenfassen: <select id="res" name="res" onchange="this.form.submit();">';
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
    if (!$_GET['3p']) {
        echo ' | Skala fixieren auf <input type="text" id="fix" name="fix" value="'.$fix_axis_y.'" size="4" onfocusout="form.submit();" /> '.$unit1.' (0 = dynamisch)';
    } else {
        echo '<input type="hidden" name="fix" value="'.$fix_axis_y.'" />';
    }
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
    if ($extra_data || $_GET['3p']) {
        $checked = $_GET['3p'] ? ' checked="checked"' : '';
        echo ' | <input id="3p" type="checkbox" name="3p" onchange="form.submit();"'.$checked.' /><label for="3p">Extradaten anzeigen</label>';
    }
    if ($pos === 0) {
        $checked = $_GET['refresh'] ? ' checked="checked"' : '';
        echo ' | <input id="refresh" type="checkbox" name="refresh" onchange="form.submit();"'.$checked.' /><label for="refresh">Grafik aktualisieren</label>';
    }
    echo '</form>';
    if (!$_GET['3p']) {
        echo ' | <button id="max" onclick="location.href=\'?file='.$files[$pos]['date'].'&max'.($_GET['refresh'] ? '&refresh' : '').'\'">#max</button>';
    }
    echo ' | <button id="reset" onclick="location.href=\'?file='.$files[$pos]['date'].'\'">Reset</button>';
    if ($power_details_resolution && !$_GET['3p']) {
        list($power_details_wh2, $power_details_wh3) = pm_calculate_power_details($power_details_wh);
        echo '<style>.cell { border: 1px solid black; padding: 2px; margin:-1px 0 0 -1px; } .head { text-align: center; font-weight: bold; }</style>';
        echo '<p></p><div style="float: left; padding-bottom: 2px;"><div class="cell head">Leistung:</div><div class="cell">Dauer:</div><div class="cell">'.($feed_measured ? $unit1_label_in : $unit1_label).':</div><div class="cell head">Leistung:</div><div class="cell">'.$unit1_label.':</div><div class="cell head">Leistung:</div><div class="cell">'.$unit1_label.':</div></div>';
        foreach ($power_details as $key => $value) {
            echo '<div style="float: left; padding-bottom: 2px;"><div class="cell head">'.($key ? '&ge;' : '&gt;').' '.$key.' '.$unit1.'</div><div class="cell">'.$value.'</div><div class="cell">'.pm_round($power_details_wh[$key], true).' '.$unit1.'h</div><div class="cell head">'.($key ? $key : $key+1).' - '.($key+$power_details_resolution-1).' '.$unit1.'</div><div class="cell">'.pm_round($power_details_wh2[$key], true).' '.$unit1.'h</div><div class="cell head">&lt; '.($key+$power_details_resolution).' '.$unit1.'</div><div class="cell">'.pm_round($power_details_wh3[$key], true).' '.$unit1.'h</div></div>';
        }
    }
    echo '</body></html>';
} elseif ($_GET['m']) {
    if (isset($_GET['feed'])) {
        $index = 6;
        $unit1_label = $unit1_label_out;
        $feed_measured = true;
    } else {
        $index = 1;
        $unit1_label = $unit1_label;
        $feed_measured = false;
    }
    $month = htmlentities(trim($_GET['m']));
    list($chart_stats) = pm_scan_chart_stats();

    for ($i = 1; $i <= 31; $i++) {
        if ($i > 28 && substr($month, -2) == '02') {
            continue;
        } elseif ($i == 31 && in_array(substr($month, -2), array('04', '06', '09', '11'))) {
            continue;
        } else {
            $chart_stats_this_month[$month.'-'.($i < 10 ? '0'.$i : $i)] = null;
        }
    }

    foreach ($chart_stats as $day => $data) {
        $this_month = substr($day, 0, 7);
        $chart_stats_months[] = $this_month;
        if ($this_month == $month) {
            $chart_stats_this_month[$day] = $data[$index];
            if (!$feed_measured && array_key_exists(6, $data)) {
                $feed_measured = true;
                $unit1_label = $unit1_label_in;
            }
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
    $params = '&fix='.$fix_axis_y.(isset($_GET['feed']) ? '&feed' : '');
    echo '<title>'.$month.' ('.$unit1_label.': '.$kwh.' k'.$unit1.'h)</title><script src="js/chart.min.js"></script><script src="js/chart_keydown.js"></script><script src="js/swipe.js"></script>';
    echo '<style>a { text-decoration: none; }</style></head><body><div style="width: 100%;"><div style="float: left;"><a id="live" href="index.php" title="Zur aktuellen Leistungsanzeige">🔌</a> <a id="overview" href="overview.php" title="Zur Übersicht">📋</a> <a id="expand" href="?y='.substr($month, 0, 4).(isset($_GET['feed']) ? '&feed' : '').'" title="Zur Jahresansicht">📅</a>'.($feed_measured ? ' <a id="feed" href="?m='.$chart_stats_months[$pos].(isset($_GET['feed']) ? '' : '&feed').'" title="Zur '.(isset($_GET['feed']) ? $unit1_label_in : $unit1_label_out).'sansicht">🔃</a>' : '').'</div><div style="float: right;"><a id="download" href="chart.php?m='.$month.'&download" title="Daten herunterladen">💾</a></div><div style="text-align: center;">';
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
                label: '".strip_tags($unit1_label)."',
                yAxisID: 'y',
                data: ".json_encode($chart_stats_this_month, JSON_NUMERIC_CHECK).",
                fill: true,
                borderWidth: 2,
                backgroundColor: [ 'rgba(109, 120, 173, 0.5)' ],
                borderColor: [ 'rgba(109, 120, 173, 1)' ],
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(context) { return context.parsed.y + ' {$unit1}h'; } } }
            },
            scales: { 
                y: { position: 'right', suggestedMin: 0,$axisY_max ticks: { callback: function(value, index, values) { return value + ' {$unit1}h'; } } },
            },
            elements: { point: { radius: 0, hitRadius: 10 } },
            maintainAspectRatio: false,
            animation: false,
            normalized: true,
        }
    });
    ctx.onclick = function(evt) {
        const points = myChart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
        if (points.length) {
            location.href = 'chart.php?file=' + Object.keys(myChart.data.datasets[points[0].datasetIndex].data)[points[0].index];
        }
    }
    ctx.onmousemove = function(evt) {
        ctx.style.cursor = myChart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true).length ? 'pointer' : 'default';
    }
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
    echo '<form method="get" style="display: inline;"><input type="hidden" name="m" value="'.$_GET['m'].'" />'.$unit1_label.' (gesamt): '.$kwh.' k'.$unit1.'h';
    if (count($chart_stats_this_month) > 1) {
        echo ' | '.$unit1_label.' (max): '.max($chart_stats_this_month).' '.$unit1.'h';
        echo ' | '.$unit1_label.' (min): '.min(array_filter($chart_stats_this_month, 'strlen')).' '.$unit1.'h';
    }
    echo ' | Skala fixieren auf <input type="text" id="fix" name="fix" value="'.$fix_axis_y.'" size="7" onfocusout="form.submit();" /> '.$unit1.'h (0 = dynamisch)';
} elseif ($_GET['y']) {
    if (isset($_GET['feed'])) {
        $index = 6;
        $unit1_label = $unit1_label_out;
        $feed_measured = true;
    } else {
        $index = 1;
        $unit1_label = $unit1_label;
        $feed_measured = false;
    }
    $year = htmlentities(trim($_GET['y']));
    list($chart_stats) = pm_scan_chart_stats();

    for ($i = 1; $i <= 12; $i++) {
        $chart_stats_this_year[$year.'-'.($i < 10 ? '0'.$i : $i)] = null;
    }

    foreach ($chart_stats as $day => $data) {
        $this_year = substr($day, 0, 4);
        $chart_stats_years[] = $this_year;
        if ($this_year == $year) {
            $chart_stats_this_year[substr($day, 0, 7)] += $data[$index]/1000;
            if (!$feed_measured && array_key_exists(6, $data)) {
                $feed_measured = true;
                $unit1_label = $unit1_label_in;
            }
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
    $params = '&fix='.$fix_axis_y.(isset($_GET['feed']) ? '&feed' : '');
    echo '<title>'.$year.' ('.$unit1_label.': '.$kwh.' k'.$unit1.'h)</title><script src="js/chart.min.js"></script><script src="js/chart_keydown.js"></script><script src="js/swipe.js"></script>';
    echo '<style>a { text-decoration: none; }</style></head><body><div style="width: 100%;"><div style="float: left;"><a id="live" href="index.php" title="Zur aktuellen Leistungsanzeige">🔌</a> <a id="overview" href="overview.php" title="Zur Übersicht">📋</a>'.($feed_measured ? ' <a id="feed" href="?y='.$chart_stats_years[$pos].(isset($_GET['feed']) ? '' : '&feed').'" title="Zur '.(isset($_GET['feed']) ? $unit1_label_in : $unit1_label_out).'sansicht">🔃</a>' : '').'</div><div style="float: right;"><a id="download" href="chart.php?y='.$year.'&download" title="Daten herunterladen">💾</a></div><div style="text-align: center;">';
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
    $feed = isset($_GET['feed']) ? '&feed' : '';
    echo "<div id=\"chartContainer\" style=\"height: 90%; width: 100%;\"><canvas id=\"myChart\"></canvas></div>
    <script>
    var ctx = document.getElementById('myChart');
    var myChart = new Chart(ctx, {
        type: 'bar',
        data: {
            datasets: [{
                label: '".strip_tags($unit1_label)."',
                yAxisID: 'y',
                data: ".json_encode($chart_stats_this_year, JSON_NUMERIC_CHECK).",
                fill: true,
                borderWidth: 2,
                backgroundColor: [ 'rgba(109, 120, 173, 0.5)' ],
                borderColor: [ 'rgba(109, 120, 173, 1)' ],
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(context) { return context.parsed.y + ' k{$unit1}h'; } } }
            },
            scales: { 
                y: { position: 'right', suggestedMin: 0,$axisY_max ticks: { callback: function(value, index, values) { return value + ' k{$unit1}h'; } } },
            },
            elements: { point: { radius: 0, hitRadius: 10 } },
            maintainAspectRatio: false,
            animation: false,
            normalized: true,
        }
    });
    ctx.onclick = function(evt) {
        const points = myChart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
        if (points.length) {
            location.href = 'chart.php?m=' + Object.keys(myChart.data.datasets[points[0].datasetIndex].data)[points[0].index] + '$feed';
        }
    }
    ctx.onmousemove = function(evt) {
        ctx.style.cursor = myChart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true).length ? 'pointer' : 'default';
    }
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
    echo '<form method="get" style="display: inline;"><input type="hidden" name="y" value="'.$_GET['y'].'" />'.$unit1_label.' (gesamt): '.$kwh.' k'.$unit1.'h';
    if (count($chart_stats_this_year) > 1) {
        echo ' | '.$unit1_label.' (max): '.max($chart_stats_this_year).' k'.$unit1.'h';
        echo ' | '.$unit1_label.' (min): '.min(array_filter($chart_stats_this_year, 'strlen')).' k'.$unit1.'h';
    }
    echo ' | Skala fixieren auf <input type="text" id="fix" name="fix" value="'.$fix_axis_y.'" size="4" onfocusout="form.submit();" /> k'.$unit1.'h (0 = dynamisch)';
} else {
    header("Location: overview.php");
}

//EOF
