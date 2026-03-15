<?php

class ShellyGen2Driver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'shelly_gen2';
    }

    public function getStats(): array
    {
        $host = $this->dc->host;
        $data = @json_decode(@file_get_contents("http://{$host}/rpc/Shelly.GetStatus"), true);

        if (!$data) {
            return ['error', 'Unable to query Shelly device. Go to <a href="overview.php">stats history</a>.'];
        }

        $power = $data['switch:0']['apower'];
        $time = $data['sys']['unixtime'];

        if (!isset($time)) {
            return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
        }

        if ($time < 500000000) {
            $time = time();
        }

        $stats_array = [
            'date' => date('d.m.Y', $time),
            'time' => date('H:i:s', $time),
            'power' => $this->pmRound($power, true, 2),
        ];

        if (isset($data['switch:0']['temperature']['tC'])) {
            $stats_array['temp'] = $this->pmRound($data['switch:0']['temperature']['tC'], true, 2);
        }

        return $stats_array;
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round($value, $numberFormat, $maxPrecision, $this->dc->rounding_precision, $this->dc->power_threshold);
    }
}

//EOF
