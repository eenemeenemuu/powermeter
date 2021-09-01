<?php

require('config.inc.php');

$files = array();
foreach(scandir($log_file_dir,  SCANDIR_SORT_DESCENDING) as $file) {
    //if (substr($file, 0, 15) != 'balkonkraftwerk') {
    if ($file == '.' || $file == '..' || $file == 'stats.txt') {
        continue;
    }
    $files[] = $file;
}

echo '<html><head><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';

if (!isset($_GET['file'])) {
    echo '<title>'.$produce_consume.'sübersicht</title></head><body><a href=".">Zurück zur aktuellen Leistungsanzeige</a><br />';
    foreach($files as $key => $file) {
        echo "<a href=\"?file=$key\">".substr($file, 0, -4)."</a><br />";
    }
    echo '</body></html>';
} else {
    $res = $_GET['res'] ? $_GET['res'] : 5;
    $t1 = isset($_GET['t1']) ? $_GET['t1'] : 0;
    $t2 = isset($_GET['t2']) ? $_GET['t2'] : 23;
    if ($t1 > $t2) {
        $t1 = $t2;
    }
    $data = file_get_contents($log_file_dir.$files[$_GET['file']]);
    $lines = explode("\n", $data);
    $date = substr($lines[0], 0, 10);
    $data = array();
    if ($res == -1) {
        foreach ($lines as $line) {
            $data[] = explode(",", $line);
        }
        $dataPoints = array();
        foreach($data as $value) {
            //if (strlen($value[2]) > 0) {
                $dataPoints[] = array("x" => $value[1], "y" => $value[2]);
            //}
        }
    } else {
        foreach ($lines as $line) {
            $data_this = explode(",", $line);
            $time_parts = explode(":", $data_this[1]);
            $data[] = array('h' => $time_parts[0], 'm' => $time_parts[1], 's' => $time_parts[2], 'p' => $data_this[2]);
        }
        $wh = 0;
        $dataPoints = array();
        //for ($h = 0; $h < 24; $h++) {
        for ($h = $t1; $h <= $t2; $h++) {
            for ($m = 0; $m < 60; $m = $m + $res) {
                $i = 0;
                $hm = 0;
                $y = 0;
                foreach($data as $value) {
                    if ($value['h'] == $h && ($value['m'] >= $m && $value['m'] < $m + $res)) {
                        $hm += $value['p'];
                        $i++;
                    }
                }
                if ($i) {
                    $y = round($hm / $i);
                    $wh += $hm / $i / 60 * $res;
                }
                //$y = $i == 0 ? 0 : round($hm / $i);
                $dataPoints[] = array("x" => ($h < 10 ? "0".$h : $h).":".($m < 10 ? "0".$m : $m), "y" => $y);
            }
        }
        if ($wh) {
            $date .= ' ('.$produce_consume.': '.round($wh).' Wh)';
        }
    }
    $get_fix = trim($_GET['fix']);
    $fix_axis_y = is_numeric($get_fix) && $get_fix >= 0 ? $get_fix : $fix_axis_y;
    if ($fix_axis_y) {
        $axisY_max = "scales: { y: { max: $fix_axis_y } }, ";
    }
    echo '<title>'.$date.'</title><script src="'.dirname($_SERVER['REQUEST_URI']).'/chart.min.js"></script></head>
        <body><a href="?">Zurück zur Übersicht</a>';
    $params = '&res='.$res.'&fix='.$fix_axis_y.'&t1='.$t1.'&t2='.$t2;
    echo '<div style="width: 100%; text-align: center">';
    if ($_GET['file'] < count($files)-1) {
        echo '<button onclick="location.href=this.children[0].href" style="cursor: pointer"><a href="?file='.($_GET['file']+1).$params.'">&laquo;</a></button> ';
    } else {
        echo '&laquo';
    }
    echo " $date ";
    if ($_GET['file'] > 0) {
        echo '<button onclick="location.href=this.children[0].href" style="cursor: pointer"><a href="?file='.($_GET['file']-1).$params.'">&raquo;</a></button>';
    } else {
        echo '&raquo;';
    }
    echo '</div>';
    echo "<div id=\"chartContainer\" style=\"height: 90%; width: 100%;\"><canvas id=\"myChart\"></canvas></div>
    <script>
    var ctx = document.getElementById('myChart');
    var myChart = new Chart(ctx, {
        type: 'line',
        data: {
            datasets: [{
                data: ".json_encode($dataPoints, JSON_NUMERIC_CHECK).",
                fill: true,
                backgroundColor: [ 'rgba(109, 120, 173, 0.7)' ],
                borderColor: [ 'rgba(109, 120, 173, 1)' ],
                borderWidth: 2,
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
            },
            elements: { point: { radius: 0, hitRadius: 50 } },
            maintainAspectRatio: false,
            animation: false,
            normalized: true,
            $axisY_max
        }
    });
    </script>";
    echo '<form method="get"><input type="hidden" name="file" value="'.$_GET['file'].'" />Messwerte zusammenfassen: <select name="res" onchange="form.submit();">';
    foreach (array('-1', '1', '5', '10', '15', '20', '30', '45', '60') as $value) {
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
    echo '</form></body></html>';
}
//EOF