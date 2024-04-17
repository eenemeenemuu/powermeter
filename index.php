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

echo '<html><head><title>'.$stats[2].' '.$unit1.' '.(isset($stats[3]) && $stats[3] !== '' ? '/ '.$stats[3].' '.$unit2.' ' : '').'['.$stats[1].' '.$stats[0].']</title>';
echo '<link rel="icon" type="image/png" href="favicon.png" /><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width" />';
echo '<script>unit1_digits = false; refresh_rate = '.($refresh_rate*1000).'; unit1 = \''.$unit1.'\'</script><script src="js/index.js"></script>';
if (isset($_GET['d']) && (int)$_GET['d']) {
    $digits = (int)$_GET['d'];
    if (isset($_GET['exclude_unit']) || $digits < 0) {
        $unit1 = '';
        $digits = abs($digits);
    }
    echo '<script>unit1_digits = '.$digits.';</script>';
    echo '<style>@font-face { font-family: "digital-7"; src: url("css/digital-7 (mono).ttf"); } body { background-color: black; margin: 0; padding: 0; } span { font-family: digital-7; font-size: 100vmin; color: red; }</style>';
    echo '</head><body>';
    $power = round($stats[2]);
    if ($power > str_repeat('9', $digits)) {
        $power = str_repeat('9', $digits);
    } elseif ($power < '-'.str_repeat('9', $digits-1)) {
        $power = '-'.str_repeat('9', $digits-1);
    }
    $power = sprintf('%0'.$digits.'d', $power);
    echo '<span><span id="power">'.$power.'</span>'.$unit1.'</span>';
} else {
    echo '<style>td { width: 50%; padding: 5px; } td.r { text-align: right; } span { font-size: x-large; }</style>';
    echo '</head><body>';
    echo '<table width="100%"><tr><td align="center"><table>';
    echo '<tr><td class="r">Aktuelle Leistung:</td><td><span id="power">'.$stats[2].'</span> '.$unit1.'</td></tr>';
    if (isset($stats[3]) && $stats[3] !== '') {
        echo '<tr><td class="r">'.$unit2_label.':</td><td><span id="temp">'.$stats[3].'</span> <span id="unit2">'.$unit2.'</span></td></tr>';
    }
    if (isset($stats[4])) {
        for ($i = 4; $i < count($stats); $i++) {
            echo '<tr><td class="r">'.${'unit'.($i-1).'_label'}.':</td><td><span id="l'.($i-3).'">'.$stats[$i].'</span> '.${'unit'.($i-1)}.'</td></tr>';
        }
    }
    echo '<tr><td class="r">Uhrzeit:</td><td><span id="time">'.$stats[1].'</span></td></tr>';
    echo '<tr><td class="r">Datum:</td><td><span id="date">'.$stats[0].'</span></td></tr>';
    echo '<tr><td class="r" valign="top">'.$unit1_label.':</td><td><p><a href="chart.php?today">Heute</a></p><p><a href="chart.php?yesterday">Gestern</a></p><p><a href="overview.php">Übersicht</a></p></td></tr>';
    echo '<tr><td class="r">Dark mode:</td><td><input id="dark_mode" type="checkbox" onclick="set_colors();"'.(isset($_GET['dm']) ? ' checked="checked"' : '').' /></td></tr>';
    echo '</table></td></tr></table>';
}
echo '</body></html>';

//EOF
