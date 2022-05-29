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

function put_contents_external($stats_string) {
    global $host_auth_key, $host_external, $log_file_dir;
    $postdata = http_build_query(['stats' => $stats_string, 'key' => $host_auth_key]);
    $opts = ['http' => ['method'  => 'POST', 'header'  => 'Content-Type: application/x-www-form-urlencoded', 'content' => $postdata]];
    $context = stream_context_create($opts);
    if (file_get_contents($host_external.'log.php', false, $context) === false) {
        // Buffer data if external host is not available
        file_put_contents($log_file_dir.'buffer.txt', $stats_string."\n", FILE_APPEND);
    }
}

function create_context($stats_string) {
    global $host_auth_key;
    $postdata = http_build_query(['stats' => $stats_string, 'key' => $host_auth_key]);
    $opts = ['http' => ['method'  => 'POST', 'header'  => 'Content-Type: application/x-www-form-urlencoded', 'content' => $postdata]];
    $context = stream_context_create($opts);
    return $context;
}

if (isset($_POST['stats']) || isset($_GET['stats'])) {
    $key = isset($_POST['key']) ? $_POST['key'] : $_GET['key'];
    if ($key == $host_auth_key) {
        $stats_string = isset($_POST['stats']) ? $_POST['stats'] : urldecode(unserialize($_GET['stats']));
        if (file_exists($log_file_dir.'stats.txt') && file_get_contents($log_file_dir.'stats.txt') == $stats_string) {
            die();
        }
        $regex_check = ['[0-9]{2}\.[0-9]{2}\.[0-9]{4}', '[0-9]{2}:[0-9]{2}:[0-9]{2}', '[0-9]{1,5}', '[\-0-9]{1,4}'];
        foreach (explode(",", $stats_string) as $stat) {
            if (!preg_match('/^'.array_shift($regex_check).'$/', $stat)) {
                die();
            }
        }
        file_put_contents($log_file_dir.date_dot2dash(substr($stats_string, 0, 10)).'.csv', $stats_string."\n", FILE_APPEND);
        file_put_contents($log_file_dir.'stats.txt', $stats_string);
    }
} else {
    for ($i = 0; $i < $log_rate; $i++) {
        $get_stats_start = microtime(true);
        $stats = GetStats();
        if ($stats[0] != 'error') {
            $stats_string = "{$stats['date']},{$stats['time']},{$stats['power']}";
            if (isset($stats['temp'])) {
                $stats_string .= ','.$stats['temp'];
            }
            if (!(file_exists($log_file_dir.'stats.txt') && file_get_contents($log_file_dir.'stats.txt') == $stats_string)) {
                file_put_contents($log_file_dir.date_dot2dash($stats['date']).'.csv', $stats_string."\n", FILE_APPEND);
                file_put_contents($log_file_dir.'stats.txt', $stats_string);
                if ($host_external) {
                    put_contents_external($stats_string);
                }
            }
        }
        if ($log_rate > 1) {
            usleep(60000000/$log_rate-round((microtime(true)-$get_stats_start)*1000000));
        }
    }
    // Send buffered data to external host if it's available again
    if ($host_external && file_exists($log_file_dir.'buffer.txt') && file_get_contents($host_external.'log.php') !== false) {
        $lines = explode("\n", file_get_contents($log_file_dir.'buffer.txt'));
        unlink($log_file_dir.'buffer.txt');
        foreach ($lines as $stats_string) {
            put_contents_external($stats_string);
        }
    }
}
//EOF
