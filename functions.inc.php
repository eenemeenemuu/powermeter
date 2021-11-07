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
            die('PHP function "mb_convert_encoding" does not exist! Try <tt>sudo apt update && sudo apt install -y php-mbstring</tt> to install.');
        }
        global $user, $pass, $ain;
        $time = time();
        $stats = file_get_contents('http://'.$host.'/webservices/homeautoswitch.lua?ain='.$ain.'&switchcmd=getbasicdevicestats&sid='.GetSessionId($user, $pass));
        $stats_array = array();
        if ($stats) {
            $stats_array['date'] = date("d.m.Y", $time);
            $stats_array['time'] = date("H:i:s", $time);

            preg_match('/<power><stats count="[0-9]+" grid="[0-9]+">([0-9]+),/', $stats, $match);
            $power = $match[1];
            $stats_array['power'] = round($power/100);

            preg_match('/<temperature><stats count="[0-9]+" grid="[0-9]+">([0-9]+),/', $stats, $match);
            $temp = $match[1];
            $stats_array['temp'] = round($temp/10);

            return $stats_array;
        } else {
            return false;
        }
    } elseif ($device == 'tasmota') {
        $obj = json_decode(file_get_contents('http://'.$host.'/cm?cmnd=Status%208'));
        if (is_int($obj->StatusSNS->ENERGY->Power)) {
            $time = strtotime($obj->StatusSNS->Time);
            $stats_array['date'] = date("d.m.Y", $time);
            $stats_array['time'] = date("H:i:s", $time);
            $stats_array['power'] = $obj->StatusSNS->ENERGY->Power;
            return $stats_array;
        }  else {
            return false;
        }
    } else {
        die('wrong device configured');
    }
}

function date_dot2dash($date) {
    $date_parts = explode('.', $date);
    return "{$date_parts[2]}-{$date_parts[1]}-{$date_parts[0]}";
}

//EOF