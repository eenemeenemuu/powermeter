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

if (isset($_POST['stats']) || isset($_GET['stats'])) {
    $key = isset($_POST['key']) ? $_POST['key'] : $_GET['key'];
    if ($key == $host_auth_key) {
        $regex_check = ['[0-9]{2}\.[0-9]{2}\.[0-9]{4}', '[0-9]{2}:[0-9]{2}:[0-9]{2}', '[\-0-9]{1,6}(\.[0-9]{1,3})?', '[\-0-9]{1,4}(\.[0-9]{1,3})?', '[\-0-9]{1,6}(\.[0-9]{1,3})?', '[\-0-9]{1,6}(\.[0-9]{1,3})?', '[\-0-9]{1,6}(\.[0-9]{1,3})?'];
        if (isset($_POST['stats'])) {
            $stats_string = $_POST['stats'];
        } elseif (@unserialize($_GET['stats']) !== false) {
            $stats_string = urldecode(unserialize($_GET['stats']));
        } elseif (isset($_GET['stats']) && preg_match('/'.implode(',', array_slice($regex_check, 0, 3)).'/', $_GET['stats'])) {
            $stats_string = $_GET['stats'];
        }
        if (!$stats_string || file_exists($log_file_dir.'stats.txt') && file_get_contents($log_file_dir.'stats.txt') == $stats_string) {
            die();
        }
        foreach (explode(",", $stats_string) as $stat) {
            if ($stat && !preg_match('/^'.array_shift($regex_check).'$/', $stat)) {
                die();
            }
        }
        file_put_contents($log_file_dir.date_dot2dash(substr($stats_string, 0, 10)).'.csv', $stats_string."\n", FILE_APPEND);
        file_put_contents($log_file_dir.'stats.txt', $stats_string);
    }
} else {
    $this_Hi = date('Hi');
    while (date('Hi') == $this_Hi) {
        $get_stats_start = microtime(true);
        $stats = GetStats();
        if (!(isset($stats[0]) && $stats[0] == 'error')) {
            $stats_string = "{$stats['date']},{$stats['time']},{$stats['power']}";
            if (isset($stats['temp'])) {
                $stats_string .= ','.$stats['temp'];
            }
            if (isset($stats['emeters'])) {
                foreach ($stats['emeters'] as $emeter) {
                    $stats_string .= ','.$emeter;
                }
            }
            if (!(file_exists($log_file_dir.'stats.txt') && file_get_contents($log_file_dir.'stats.txt') == $stats_string)) {
                file_put_contents($log_file_dir.date_dot2dash($stats['date']).'.csv', $stats_string."\n", FILE_APPEND);
                file_put_contents($log_file_dir.'stats.txt', $stats_string);
                if (isset($log_extra_array) && $log_extra_array > 0) {
                    $power_array = json_decode(file_get_contents($log_file_dir.'power_array'));
                    $power_array[] = $stats_string;
                    if (count($power_array) > $log_extra_array) {
                        array_shift($power_array);
                    }
                    file_put_contents($log_file_dir.'power_array', json_encode($power_array));
                }
                if ($host_external) {
                    put_contents_external($stats_string);
                }
            }
        }
        $microseconds = 60000000/$log_rate-round((microtime(true)-$get_stats_start)*1000000);
        if ($microseconds > 0) {
            usleep($microseconds);
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
