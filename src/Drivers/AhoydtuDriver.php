<?php

class AhoydtuDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'ahoydtu';
    }

    public function getStats(): array
    {
        $host = $this->dc->host;
        $inverterId = $this->dc->inverter_id;
        $data = @json_decode(@file_get_contents("http://{$host}/api/inverter/id/{$inverterId}"));

        if (is_object($data) && $data->ts_last_success) {
            $time = $data->ts_last_success;
            $stats_array = [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($data->ch[0][2], true, 1),
                'temp' => $this->pmRound($data->ch[0][5], true, 1),
            ];

            if (array_key_exists(2, $data->ch)) {
                $stats_array['emeters'][] = $this->pmRound($data->ch[1][2], true, 1);
                $stats_array['emeters'][] = $this->pmRound($data->ch[2][2], true, 1);
                if (array_key_exists(4, $data->ch)) {
                    $stats_array['emeters'][] = $this->pmRound($data->ch[3][2], true, 1);
                    $stats_array['emeters'][] = $this->pmRound($data->ch[4][2], true, 1);
                }
            } else {
                $stats_array['emeters'][] = $this->pmRound($data->ch[1][2], true, 1);
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
