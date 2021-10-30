<?php
require('config.inc.php');

if (!$use_cache || isset($_GET['nocache'])) {
    require('functions.inc.php');
    $stats = array();
    foreach (GetStats() as $value) {
        $stats[] = $value;
    }
} else {
    $stats = explode (',', file_get_contents($log_file_dir.'stats.txt'));
}

if (isset($_GET['ajax'])) {
    echo implode(',', $stats);
    die();
}

if (isset($stats[3])) {
    $temp_title = '/ '.$stats[3].' °C ';
    $temp_body = 'Temperatur: <span id="temp">'.$stats[3].'</span> °C<br />';
}

echo '<html><head><title>'.$stats[2].' W '.$temp_title.'['.$stats[1].' '.$stats[0].']</title><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" /><script>refresh_rate = '.($refresh_rate*1000).';</script><script src="ajax.js"></script></head><body>';
echo 'Aktuelle Leistung: <span id="power">'.$stats[2].'</span> W<br />'.$temp_body.'Uhrzeit: <span id="time">'.$stats[1].'</span><br /> Datum: <span id="date">'.$stats[0].'</span>';
echo '<br />'.$produce_consume.': <a href="chart.php?today">Heute</a> | <a href="chart.php?yesterday">Gestern</a> | <a href="chart.php">Übersicht</a>';
echo '</body></html>';

//EOF