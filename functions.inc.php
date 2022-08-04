<?php

function GetSessionId ($user, $pass) {
    global $host;
    $text = file_get_contents('http://'.$host.'/login_sid.lua');
    preg_match('/<SID>(.*)<\/SID>/', $text, $match);
    $sid = $match[1];
    if ($sid == "0000000000000000") {
        preg_match('/<Challenge>(.*)<\/Challenge>/', $text, $match);
        $challenge = $match[1];
        $text = file_get_contents('http://'.$host.'/login_sid.lua?username='.$user.'&response='.$challenge."-".md5(mb_convert_encoding($challenge."-".$pass, 'UTF-16LE', 'UTF-8')));
        //print_r($text);
        preg_match('/<SID>(.*)<\/SID>/', $text, $match);
        $sid = $match[1];
    }
    return $sid; 
}

function GetStats() {
    global $device, $host;
    if ($device == 'fritzbox') {
        if (!function_exists('mb_convert_encoding')) {
            return ['error', 'PHP function "mb_convert_encoding" does not exist! Try <code>sudo apt update && sudo apt install -y php-mbstring</code> to install.'];
        }
        global $user, $pass, $ain;
        $time = time();
        $stats = file_get_contents('http://'.$host.'/webservices/homeautoswitch.lua?ain='.$ain.'&switchcmd=getbasicdevicestats&sid='.GetSessionId($user, $pass));
        $stats_array = [];
        if ($stats) {
            $stats_array['date'] = date("d.m.Y", $time);
            $stats_array['time'] = date("H:i:s", $time);

            if (!preg_match('/<voltage><stats count="[0-9]+" grid="[0-9]+">([0-9]+),/', $stats)) {
                return (array('error', 'FRITZ!DECT seems to be offline, please check.'));
            }

            preg_match('/<power><stats count="[0-9]+" grid="[0-9]+">([0-9]+),/', $stats, $match);
            $power = $match[1];
            $stats_array['power'] = pm_round($power/100, true, 2);

            preg_match('/<temperature><stats count="[0-9]+" grid="[0-9]+">([\-0-9]+),/', $stats, $match);
            $temp = $match[1];
            $stats_array['temp'] = pm_round($temp/10, true, 1);

            return $stats_array;
        } else {
            return (array('error', 'Unable to get stats. Please check host, username, password and ain configuration. Go to <a href="overview.php">stats history</a>.'));
        }
    } elseif ($device == 'tasmota') {
        $obj = json_decode(file_get_contents('http://'.$host.'/cm?cmnd=Status%208'));
        if (is_int($obj->StatusSNS->ENERGY->Power)) {
            $time = strtotime($obj->StatusSNS->Time);
            if ($time < 500000000) {
                $time = time();
            }
            $stats_array['date'] = date("d.m.Y", $time);
            $stats_array['time'] = date("H:i:s", $time);
            $stats_array['power'] = pm_round($obj->StatusSNS->ENERGY->Voltage*$obj->StatusSNS->ENERGY->Current*$obj->StatusSNS->ENERGY->Factor, true, 3);

            return $stats_array;
        } else {
            return (array('error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'));
        }
    } elseif ($device == 'shelly3em') {
        $data = json_decode(file_get_contents('http://'.$host.'/status'), true);
        if ($data) {
            $time = $data['unixtime'];
            if ($time < 500000000) {
                $time = time();
            }
            $stats_array['date'] = date("d.m.Y", $time);
            $stats_array['time'] = date("H:i:s", $time);
            $stats_array['power'] = pm_round($data['total_power'], true, 2);
            $stats_array['temp'] = '';
            foreach ($data['emeters'] as $emeter) {
                $stats_array['emeters'][] = $emeter['power'];
            }
            return $stats_array;
        } else {
            return (array('error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'));
        }
    } elseif ($device == 'shelly') {
        $data = json_decode(file_get_contents('http://'.$host.'/status'), true);

        if (!$data) {
            return (array('error', 'Unable to query Shelly device. Go to <a href="overview.php">stats history</a>.'));
        }

        $power = 0;
        foreach ($data['meters'] as $meter) {
            if ($meter['is_valid']){
                $power += $meter['power'];
                $time = $meter['timestamp'];
            }
        }

        if (!isset($time)) {
            return (array('error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'));
        }

        if ($time < 500000000) {
            $time = time();
        }

        $stats_array['date'] = DateTime::createFromFormat('U', $time)->format("d.m.Y");
        $stats_array['time'] = DateTime::createFromFormat('U', $time)->format("H:i:s");
        $stats_array['power'] = pm_round($power, true, 2);
        if (isset($data['temperature'])) {
            $stats_array['temp'] = pm_round($data['temperature'], true, 2);
        }

        return $stats_array;
    } elseif ($device == 'envtec') {
        global $station_id;

        $opts = ['http' => ['method'  => 'POST', 'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: 0\r\n" ]];
        $context = stream_context_create($opts);
        $url = "https://www.envertecportal.com/ApiInverters/QueryTerminalReal?page=1&perPage=20&orderBy=GATEWAYSN&whereCondition=%7B%22STATIONID%22%3A%22{$station_id}%22%7D";
        $result = file_get_contents($url, false, $context);

        if (!$result) {
            return (array('error', 'Unable to query envertecportal.com. Go to <a href="overview.php">stats history</a>.'));
        }

        $data = json_decode($result, true);

        if (!$data['Data']['QueryResults']) {
            return (array('error', 'Unable to get stats. Please check station ID configuration. Go to <a href="overview.php">stats history</a>.'));
        }

        foreach ($data['Data']['QueryResults'] as $result) {
            $data_timestamps[] = $result['SITETIME'];
        }
        $stats_timestamp = max($data_timestamps);

        $skipped = 0;
        foreach ($data['Data']['QueryResults'] as $result) {
            if (!$result['SITETIME']) {
                continue;
            }
            if ($result['SITETIME'] != $stats_timestamp) {
                // skip outdated (missing) data
                $skipped++;
            } else {
                $stats_power[] = $result['POWER'];
                $stats_temp[] = $result['TEMPERATURE'];
            }
        }

        $timeZone = new DateTimeZone('Europe/London');
        $dateTime = DateTime::createFromFormat('m/d/Y h:i:s A', $stats_timestamp, $timeZone);
        $stats_array['date'] = $dateTime->setTimezone((new DateTimeZone('Europe/Berlin')))->format("d.m.Y");
        $stats_array['time'] = $dateTime->setTimezone((new DateTimeZone('Europe/Berlin')))->format("H:i:s");
        $stats_array['power'] = array_sum($stats_power);
        $stats_array['temp'] = pm_round(array_sum($stats_temp)/count($stats_temp), true, 1);

        if ($skipped) {
            // assume power of the skipped modules are identical
            $i = count($data['Data']['QueryResults']);
            $stats_array['power'] = $stats_array['power'] / $i * ($i + $skipped);
        }
        $stats_array['power'] = pm_round($stats_array['power'], true, 2);

        return $stats_array;
    } else {
        return (array('error', 'Invalid device configured.'));
    }
}

function date_dot2dash($date) {
    $date_parts = explode('.', $date);
    return "{$date_parts[2]}-{$date_parts[1]}-{$date_parts[0]}";
}

function pm_round($value, $number_format = false, $max_precision_level = 9) {
    global $rounding_precision, $power_threshold;
    if ($value < $power_threshold) {
        return 0;
    }
    if ($number_format && $rounding_precision) {
        return number_format($value, min($rounding_precision, $max_precision_level), '.', '');
    } else {
        return round($value, $rounding_precision);
    }
}

function pm_scan_log_file_dir() {
    global $log_file_dir;
    $i = 0;
    $pos = false;
    $files = [];
    foreach (scandir($log_file_dir, SCANDIR_SORT_DESCENDING) as $file) {
        if ($file == '.' || $file == '..' || $file == 'stats.txt' || $file == 'chart_stats.csv' || substr($file, 0, 14) == 'chart_details_' || $file == 'buffer.txt') {
            continue;
        }
        if (isset($_GET['file']) && ($file == $_GET['file'] || $file == $_GET['file'].'.csv' || $file == $_GET['file'].'.csv.gz' || $file == $_GET['file'].'.gz')) {
            $pos = $i;
        }
        $i++;
        $files[] = array('date' => substr($file, 0, strpos($file, '.')), 'name' => $file);
    }
    foreach ($files as $key => $file) {
        $file_dates[] = $file['date'];
    }
    array_unique($file_dates);
    return [$files, $pos, $file_dates];
}

function pm_scan_chart_stats() {
    global $log_file_dir, $file_dates;
    foreach (explode("\n", file_get_contents($log_file_dir.'chart_stats.csv')) as $line) {
        $stat_parts = explode(',', $line);
        if ($stat_parts[0]) {
            $chart_stats[$stat_parts[0]] = $stat_parts;
            $date_parts = explode('-', $stat_parts[0]);
            $chart_stats_month[$date_parts[0]][$date_parts[1]] += $stat_parts[1];
            if (isset($stat_parts[6])) {
                $chart_stats_month_feed[$date_parts[0]][$date_parts[1]] += $stat_parts[6];
            }
        }
    }
    return [$chart_stats, $chart_stats_month, $chart_stats_month_feed];
}

//EOF
