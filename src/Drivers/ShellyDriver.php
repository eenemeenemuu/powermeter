<?php

class ShellyDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'shelly';
    }

    public function getStats(): array
    {
        $host = $this->dc->host;
        $data = @json_decode(@file_get_contents("http://{$host}/status"), true);

        if (!$data) {
            return ['error', 'Unable to query Shelly device. Go to <a href="overview.php">stats history</a>.'];
        }

        $power = 0;
        $time = null;
        foreach ($data['meters'] as $meter) {
            if ($meter['is_valid']) {
                $power += $meter['power'];
                $time = $meter['timestamp'];
            }
        }

        if (!isset($time)) {
            return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
        }

        if ($time < 500000000) {
            $time = time();
        }

        $stats_array = [
            'date' => DateTime::createFromFormat('U', $time)->format('d.m.Y'),
            'time' => DateTime::createFromFormat('U', $time)->format('H:i:s'),
            'power' => $this->pmRound($power, true, 2),
        ];

        if (isset($data['temperature'])) {
            $stats_array['temp'] = $this->pmRound($data['temperature'], true, 2);
        }

        return $stats_array;
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round($value, $numberFormat, $maxPrecision, $this->dc->rounding_precision, $this->dc->power_threshold);
    }
}

//EOF
