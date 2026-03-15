<?php

class FritzboxDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'fritzbox';
    }

    public function getStats(): array
    {
        if (!function_exists('mb_convert_encoding')) {
            return ['error', 'PHP function "mb_convert_encoding" does not exist! Try <code>sudo apt update && sudo apt install -y php-mbstring</code> to install.'];
        }

        $host = $this->dc->host;
        $user = $this->dc->user;
        $pass = $this->dc->pass;
        $ain = $this->dc->ain;

        $sid = $this->getSessionId($host, $user, $pass);

        $time = time();
        $stats = @file_get_contents("http://{$host}/webservices/homeautoswitch.lua?ain={$ain}&switchcmd=getbasicdevicestats&sid={$sid}");
        $stats_array = [];

        if ($stats) {
            $stats_array['date'] = date('d.m.Y', $time);
            $stats_array['time'] = date('H:i:s', $time);

            if (!preg_match('/<voltage><stats count="[0-9]+" grid="[0-9]+"(?: datatime="[0-9]+")?>([0-9]+),/', $stats)) {
                return ['error', 'FRITZ!DECT seems to be offline, please check.'];
            }

            preg_match('/<power><stats count="[0-9]+" grid="[0-9]+"(?: datatime="[0-9]+")?>([0-9]+),/', $stats, $match);
            $stats_array['power'] = $this->pmRound($match[1] / 100, true, 2);

            preg_match('/<temperature><stats count="[0-9]+" grid="[0-9]+"(?: datatime="[0-9]+")?>([\-0-9]+),/', $stats, $match);
            $stats_array['temp'] = $this->pmRound($match[1] / 10, true, 1);

            return $stats_array;
        }

        return ['error', 'Unable to get stats. Please check host, username, password and ain configuration. Go to <a href="overview.php">stats history</a>.'];
    }

    private function getSessionId(string $host, string $user, string $pass): string
    {
        $text = @file_get_contents("http://{$host}/login_sid.lua");
        preg_match('/<SID>(.*)<\/SID>/', $text, $match);
        $sid = $match[1];

        if ($sid == '0000000000000000') {
            preg_match('/<Challenge>(.*)<\/Challenge>/', $text, $match);
            $challenge = $match[1];
            $response = $challenge . '-' . md5(mb_convert_encoding($challenge . '-' . $pass, 'UTF-16LE', 'UTF-8'));
            $text = @file_get_contents("http://{$host}/login_sid.lua?username={$user}&response={$response}");
            preg_match('/<SID>(.*)<\/SID>/', $text, $match);
            $sid = $match[1];
        }

        return $sid;
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round($value, $numberFormat, $maxPrecision, $this->dc->rounding_precision, $this->dc->power_threshold);
    }
}

//EOF
