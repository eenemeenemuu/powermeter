<?php
require('config.inc.php');
require('functions.inc.php');

if (!$use_cache || isset($_GET['nocache'])) {
    $stats_array = GetStats();
    if ($stats_array[0] == 'error') {
        die($stats_array[1]);
    }
    $stats = array();
    foreach ($stats_array as $value) {
        if (is_array($value)) {
            foreach ($value as $array_value) {
                $stats[] = $array_value;
            }
        } else {
            $stats[] = $value;
        }
    }
} else {
    $stats = explode (',', file_get_contents($log_file_dir.'stats.txt'));
}

if (isset($_GET['ajax'])) {
    echo implode(',', $stats);
    die();
}

echo '<html><head><title>'.$stats[2].' W '.(isset($stats[3]) && $stats[3] !== '' ? '/ '.$stats[3].' '.$temp_unit.' ' : '').'['.$stats[1].' '.$stats[0].']</title>';
echo '<link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';
echo '<script>refresh_rate = '.($refresh_rate*1000).';</script><script src="js/index.js"></script>';
echo '<style>td { width: 50%; padding: 5px; } td.r { text-align: right; } span { font-size: x-large; }</style>';
echo '</head><body>';
echo '<table width="100%"><tr><td align="center"><table>';
echo '<tr><td class="r">Aktuelle Leistung:</td><td><span id="power">'.$stats[2].'</span> W</td></tr>';
if (isset($stats[4])) {
    for ($i = 4; $i < count($stats); $i++) {
        echo '<tr><td class="r">L'.($i-3).':</td><td><span id="l'.($i-3).'">'.$stats[$i].'</span> W</td></tr>';
    }
}
if (isset($stats[3]) && $stats[3] !== '') {
    echo '<tr><td class="r">'.$temp_label.':</td><td><span id="temp">'.$stats[3].'</span> <span id="temp_unit">'.$temp_unit.'</span></td></tr>';
}
echo '<tr><td class="r">Uhrzeit:</td><td><span id="time">'.$stats[1].'</span></td></tr>';
echo '<tr><td class="r">Datum:</td><td><span id="date">'.$stats[0].'</span></td></tr>';
echo '<tr><td class="r" valign="top">'.$produce_consume.':</td><td><p><a href="chart.php?today">Heute</a></p><p><a href="chart.php?yesterday">Gestern</a></p><p><a href="overview.php">Übersicht</a></p></td></tr>';
echo '<tr><td class="r">Dark mode:</td><td><input id="dark_mode" type="checkbox" onclick="set_colors();"'.(isset($_GET['dm']) ? ' checked="checked"' : '').' /></td></tr>';
echo '</table></td></tr></table>';
echo '</body></html>';

//EOF
