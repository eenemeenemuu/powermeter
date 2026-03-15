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

function put_contents_external($stats_string, $buffer = false) {
    global $host_auth_key, $host_external, $log_file_dir, $log_rate;
    if ($buffer) {
        $postdata = http_build_query(['stats' => $stats_string, 'key' => $host_auth_key, 'buffer' => '1']);
    } else {
        $postdata = http_build_query(['stats' => $stats_string, 'key' => $host_auth_key]);
    }
    $opts = ['http' => ['method'  => 'POST', 'header'  => 'Content-Type: application/x-www-form-urlencoded', 'content' => $postdata, 'timeout' => ceil(60/$log_rate)]];
    $context = stream_context_create($opts);
    if (file_get_contents($host_external.'log.php', false, $context) === false) {
        // Buffer data if external host is not available
        file_put_contents($log_file_dir.'buffer.txt', $stats_string."\n", FILE_APPEND);
    }
}

if (isset($_POST['stats']) || isset($_GET['stats'])) {
    $key = isset($_POST['key']) ? $_POST['key'] : $_GET['key'];
    if ($key == $host_auth_key) {
        $regex_check = ['[0-9]{2}\.[0-9]{2}\.[0-9]{4}', '[0-9]{2}:[0-9]{2}:[0-9]{2}'];
        for ($i = 0; $i < 14; $i++) {
            $regex_check[] = '[\-0-9]{1,6}(\.[0-9]{1,3})?';
        } 
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
        if (!(isset($_POST['buffer']) && $_POST['buffer'] == '1')) {
            file_put_contents($log_file_dir.'stats.txt', $stats_string);
        }
    }
} else {
    // Determine if multi-device mode is active
    $config = Config::load(file_exists('config.inc.php') ? 'config.inc.php' : null);
    $isMultiDevice = $config->isMultiDevice();
    $errors = [];

    if ($log_rate > 0) {
        $this_Hi = date('Hi');
        while (date('Hi') == $this_Hi) {
            $get_stats_start = microtime(true);

            if ($isMultiDevice) {
                // Multi-device: query all devices and log each separately
                $manager = new DeviceManager($config);
                $allResults = $manager->queryAllAsync();

                foreach ($allResults as $deviceId => $stats) {
                    if (isset($stats[0]) && $stats[0] == 'error') {
                        $errors[] = "[{$deviceId}] " . $stats[1];
                        continue;
                    }

                    $stats_string = "{$stats['date']},{$stats['time']},{$stats['power']}";
                    if (isset($stats['temp'])) {
                        $stats_string .= ',' . $stats['temp'];
                    }
                    if (isset($stats['emeters'])) {
                        foreach ($stats['emeters'] as $emeter) {
                            $stats_string .= ',' . $emeter;
                        }
                    }

                    $deviceDir = $log_file_dir . $deviceId . '/';
                    if (!is_dir($deviceDir)) {
                        mkdir($deviceDir, 0777, true);
                    }

                    $deviceStatsFile = $deviceDir . 'stats.txt';
                    if (!(file_exists($deviceStatsFile) && file_get_contents($deviceStatsFile) == $stats_string)) {
                        if (!$log_external_only) {
                            file_put_contents($deviceDir . date_dot2dash($stats['date']) . '.csv', $stats_string . "\n", FILE_APPEND);
                        }
                        file_put_contents($deviceStatsFile, $stats_string);
                    }
                }

                // Also write combined stats to main stats.txt for index.php compatibility
                $firstResult = reset($allResults);
                if ($firstResult && !isset($firstResult[0])) {
                    $combined_string = "{$firstResult['date']},{$firstResult['time']},{$firstResult['power']}";
                    if (isset($firstResult['temp'])) {
                        $combined_string .= ',' . $firstResult['temp'];
                    }
                    if (isset($firstResult['emeters'])) {
                        foreach ($firstResult['emeters'] as $emeter) {
                            $combined_string .= ',' . $emeter;
                        }
                    }
                    file_put_contents($log_file_dir . 'stats.txt', $combined_string);
                }
            } else {
                // Single-device: original behavior
                $stats = GetStats();
                if (!(isset($stats[0]) && $stats[0] == 'error')) {
                    $stats_string = "{$stats['date']},{$stats['time']},{$stats['power']}";
                    if (isset($stats['temp'])) {
                        $stats_string .= ',' . $stats['temp'];
                    }
                    if (isset($stats['emeters'])) {
                        foreach ($stats['emeters'] as $emeter) {
                            $stats_string .= ',' . $emeter;
                        }
                    }
                    if (!(file_exists($log_file_dir . 'stats.txt') && file_get_contents($log_file_dir . 'stats.txt') == $stats_string)) {
                        if (!$log_external_only) {
                            file_put_contents($log_file_dir . date_dot2dash($stats['date']) . '.csv', $stats_string . "\n", FILE_APPEND);
                        }
                        file_put_contents($log_file_dir . 'stats.txt', $stats_string);
                        if (isset($log_extra_array) && $log_extra_array > 0) {
                            $power_array = json_decode(file_get_contents($log_file_dir . 'power_array'));
                            $power_array[] = $stats_string;
                            if (count($power_array) > $log_extra_array) {
                                array_shift($power_array);
                            }
                            file_put_contents($log_file_dir . 'power_array', json_encode($power_array));
                        }
                        if ($host_external) {
                            put_contents_external($stats_string);
                        }
                    }
                } elseif ($stats[0] == 'error') {
                    $errors[] = $stats[1];
                }
            }

            $microseconds = (int) 60000000 / $log_rate - round((microtime(true) - $get_stats_start) * 1000000);
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        }
    } else {
        $errors[] = 'Please check log rate configuration.';
    }

    if ($errors) {
        echo 'Errors:<ul><li>' . implode('</li><li>', $errors) . '</li></ul>';
    }

    // Send buffered data to external host if it's available again
    if ($host_external && file_exists($log_file_dir . 'buffer.txt') && file_get_contents($host_external . 'log.php?stats=test') !== false) {
        $lines = explode("\n", file_get_contents($log_file_dir . 'buffer.txt'));
        unlink($log_file_dir . 'buffer.txt');
        foreach ($lines as $stats_string) {
            put_contents_external($stats_string, true);
            sleep(1);
        }
    }
}
//EOF
