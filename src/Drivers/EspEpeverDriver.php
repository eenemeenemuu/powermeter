<?php

class EspEpeverDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'esp-epever-controller';
    }

    public function getStats(): array
    {
        $host = $this->dc->host;
        $time = time();
        $data = @json_decode(@file_get_contents("http://{$host}/AllJsonData", false, stream_context_create(['http' => ['timeout' => 1]])));

        if (is_object($data) && $data->BatteryV) {
            return [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($data->PanelP, true, 2),
                'temp' => $this->pmRound($data->BatteryV, true, 2),
                'emeters' => [
                    $this->pmRound($data->BatteryI, true, 2),
                    $this->pmRound($data->PanelV, true, 2),
                    $this->pmRound($data->PanelI, true, 2),
                ],
            ];
        }

        return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round($value, $numberFormat, $maxPrecision, $this->dc->rounding_precision, $this->dc->power_threshold);
    }
}

//EOF
