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

if (isset($stats[3])) {
    $temp_title = '/ '.$stats[3].' °C ';
    $temp_body = 'Temperatur: '.$stats[3].' °C<br />';
}

echo '<html><head><title>'.$stats[2].' W '.$temp_title.'['.$stats[1].' '.$stats[0].']</title><link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" /><meta http-equiv="refresh" content="'.$refresh_rate.'" /></head><body>';
echo 'Aktuelle Leistung: '.$stats[2].' W<br />'.$temp_body.'Uhrzeit: '.$stats[1].'<br /> Datum: '.$stats[0];
echo '<br />'.$produce_consume.': <a href="chart.php?today">Heute</a> | <a href="chart.php">Übersicht</a>';
echo '</body></html>';

//EOF