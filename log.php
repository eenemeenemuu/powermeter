<?php
require('config.inc.php');
require('functions.inc.php');

if (!is_dir($log_file_dir)) {
    if (!mkdir($log_file_dir)) {
        die('Failed to create log file directory.');
    }
}
if (!file_put_contents($log_file_dir.'test', 'test')) {
    die('Failed to write to log file directory.');
} else {
    unlink($log_file_dir.'test');
}

if ($_GET['stats']) {
    if ($_GET['key'] == $host_auth_key) {
        $stats_string = urldecode(unserialize($_GET['stats']));
        file_put_contents($log_file_dir.date_dot2dash(substr($stats_string, 0, 10)).'.csv', $stats_string."\n", FILE_APPEND);
        file_put_contents($log_file_dir.'stats.txt', $stats_string);
    }
} else {
    for ($i = 0; $i < $log_rate; $i++) {
        $stats = GetStats();
        if ($stats[0] != 'error') {
            $stats_string = "{$stats['date']},{$stats['time']},{$stats['power']}";
            if (isset($stats['temp'])) {
                $stats_string .= ','.$stats['temp'];
            }
            file_put_contents($log_file_dir.date_dot2dash($stats['date']).'.csv', $stats_string."\n", FILE_APPEND);
            file_put_contents($log_file_dir.'stats.txt', $stats_string);
            if ($host_external) {
                file_get_contents($host_external.'log.php?stats='.urlencode(serialize($stats_string)).'&key='.$host_auth_key);
            }
        }
        if ($log_rate > 1) {
            if ($device == 'fritzbox') {
                sleep(60/$log_rate-1);
            } else {
                sleep(60/$log_rate);
            }
        }
    }
}
//EOF