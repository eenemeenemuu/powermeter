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
            return ['error', 'PHP function "mb_convert_encoding" does not exist! Try sudo apt update && sudo apt install -y php-mbstring to install.'];
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
            $stats_array['power'] = round($power/100);

            preg_match('/<temperature><stats count="[0-9]+" grid="[0-9]+">([\-0-9]+),/', $stats, $match);
            $temp = $match[1];
            $stats_array['temp'] = round($temp/10);

            return $stats_array;
        } else {
            return (array('error', 'Unable to get stats. Please check host, username, password and ain configuration. Go to <a href="chart.php">stats history</a>.'));
        }
    } elseif ($device == 'tasmota') {
        $obj = json_decode(file_get_contents('http://'.$host.'/cm?cmnd=Status%208'));
        if (is_int($obj->StatusSNS->ENERGY->Power)) {
            $time = strtotime($obj->StatusSNS->Time);
            $stats_array['date'] = date("d.m.Y", $time);
            $stats_array['time'] = date("H:i:s", $time);
            $stats_array['power'] = $obj->StatusSNS->ENERGY->Power;
            return $stats_array;
        } else {
            return (array('error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="chart.php">stats history</a>.'));
        }
    } elseif ($device == 'envtec') {
        global $station_id;

        $opts = ['http' =>
            [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: 0\r\n",
            ]
        ];

        $context  = stream_context_create($opts);

        $url = "https://www.envertecportal.com/ApiInverters/QueryTerminalReal?page=1&perPage=20&orderBy=GATEWAYSN&whereCondition=%7B%22STATIONID%22%3A%22{$station_id}%22%7D";

        $result = file_get_contents($url, false, $context);
        $data = json_decode($result, true);

        if (!$result) {
            return (array('error', 'Unable to query envertecportal.com. Go to <a href="chart.php">stats history</a>.'));
        } elseif (!$data['Data']['QueryResults']) {
            return (array('error', 'Unable to get stats. Please check station ID configuration. Go to <a href="chart.php">stats history</a>.'));
        } else {
            foreach ($data['Data']['QueryResults'] as $result) {
                $timeZone = new DateTimeZone('Europe/London');
                $dateTime = DateTime::createFromFormat('m/d/Y h:i:s A', $result['SITETIME'], $timeZone);;
                $stats_array['date'] = $dateTime->setTimezone((new DateTimeZone('Europe/Berlin')))->format("d.m.Y");
                $stats_array['time'] = $dateTime->setTimezone((new DateTimeZone('Europe/Berlin')))->format("H:i:s");
                $stats_array['power'] += $result['POWER'];
                $stats_array['temp'] = round($result['TEMPERATURE']);
            }
            $stats_array['power'] = round($stats_array['power']);
            return $stats_array;
        }
    } else{
        return (array('error', 'Invalid device configured.'));
    }
}

function date_dot2dash($date) {
    $date_parts = explode('.', $date);
    return "{$date_parts[2]}-{$date_parts[1]}-{$date_parts[0]}";
}

//EOF
