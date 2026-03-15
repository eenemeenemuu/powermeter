<?php

class TasmotaDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'tasmota';
    }

    public function getStats(): array
    {
        $host = $this->dc->host;
        $obj = @json_decode(@file_get_contents("http://{$host}/cm?cmnd=Status%208"));

        if (is_object($obj) && is_int($obj->StatusSNS->ENERGY->Power)) {
            $time = strtotime($obj->StatusSNS->Time);
            if ($time < 500000000) {
                $time = time();
            }
            return [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($obj->StatusSNS->ENERGY->Voltage * $obj->StatusSNS->ENERGY->Current * $obj->StatusSNS->ENERGY->Factor, true, 3),
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
