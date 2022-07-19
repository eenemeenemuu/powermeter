<?php
require('config.inc.php');

if (!$use_cache || isset($_GET['nocache'])) {
    require('functions.inc.php');
    $stats_array = GetStats();
    if ($stats_array[0] == 'error') {
        die($stats_array[1]);
    }
    $stats = array();
    foreach ($stats_array as $value) {
        $stats[] = $value;
    }
} else {
    $stats = explode (',', file_get_contents($log_file_dir.'stats.txt'));
}

if (isset($_GET['ajax'])) {
    echo implode(',', $stats);
    die();
}

echo '<html><head><title>'.$stats[2].' W '.(isset($stats[3]) ? '/ '.$stats[3].' °C ' : '').'['.$stats[1].' '.$stats[0].']</title>';
echo '<link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';
echo '<script>refresh_rate = '.($refresh_rate*1000).';</script><script src="js/ajax.js"></script>';
echo '<style>body { background-color: #252525; } td { background-color: white; width: 50%; padding: 5px; } td.r { text-align: right; } span { font-size: x-large; }</style>';
echo '</head><body>';
echo '<table width="100%"><tr><td align="center"><table>';
echo '<tr><td class="r">Aktuelle Leistung:</td><td><span id="power">'.$stats[2].'</span> W</td></tr>';
if (isset($stats[3])) {
    echo '<tr><td class="r">Temperatur:</td><td><span id="temp">'.$stats[3].'</span> °C</td></tr>';
}
echo '<tr><td class="r">Uhrzeit:</td><td><span id="time">'.$stats[1].'</span></td></tr>';
echo '<tr><td class="r">Datum:</td><td><span id="date">'.$stats[0].'</span></td></tr>';
echo '<tr><td class="r" valign="top">'.$produce_consume.':</td><td><p><a href="chart.php?today">Heute</a></p><p><a href="chart.php?yesterday">Gestern</a></p><p><a href="overview.php">Übersicht</a></p></td></tr>';
echo '</table></td></tr></table>';
echo '</body></html>';

//EOF
