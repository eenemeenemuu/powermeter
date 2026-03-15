<?php

class Shelly3emDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'shelly3em';
    }

    public function getStats(): array
    {
        $host = $this->dc->host;
        $data = @json_decode(@file_get_contents("http://{$host}/status"), true);

        if ($data) {
            $time = $data['unixtime'];
            if ($time < 500000000) {
                $time = time();
            }
            $stats_array = [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($data['total_power'], true, 2),
                'temp' => '',
            ];
            foreach ($data['emeters'] as $emeter) {
                $stats_array['emeters'][] = $emeter['power'];
            }
            return $stats_array;
        }

        return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round($value, $numberFormat, $maxPrecision, $this->dc->rounding_precision, $this->dc->power_threshold);
    }
}

//EOF
