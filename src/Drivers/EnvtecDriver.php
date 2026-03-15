<?php

class EnvtecDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'envtec';
    }

    public function getStats(): array
    {
        $stationId = $this->dc->station_id;
        $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: 0\r\n"]];
        $context = stream_context_create($opts);
        $url = "https://www.envertecportal.com/ApiInverters/QueryTerminalReal?page=1&perPage=20&orderBy=GATEWAYSN&whereCondition=" . urlencode('{"STATIONID":"' . $stationId . '"}');
        $result = @file_get_contents($url, false, $context);

        if (!$result) {
            return ['error', 'Unable to query envertecportal.com. Go to <a href="overview.php">stats history</a>.'];
        }

        $data = json_decode($result, true);

        if (!$data['Data']['QueryResults']) {
            return ['error', 'Unable to get stats. Please check station ID configuration. Go to <a href="overview.php">stats history</a>.'];
        }

        $data_timestamps = [];
        foreach ($data['Data']['QueryResults'] as $result) {
            $data_timestamps[] = $result['SITETIME'];
        }
        $stats_timestamp = max($data_timestamps);

        $skipped = 0;
        $stats_power = [];
        $stats_temp = [];
        foreach ($data['Data']['QueryResults'] as $result) {
            if (!$result['SITETIME']) {
                continue;
            }
            if ($result['SITETIME'] != $stats_timestamp) {
                $skipped++;
            } else {
                $stats_power[] = $result['POWER'];
                $stats_temp[] = $result['TEMPERATURE'];
            }
        }

        $timeZone = new DateTimeZone('Europe/Helsinki');
        $dateTime = DateTime::createFromFormat('m/d/Y h:i:s A', $stats_timestamp, $timeZone);
        $berlinTz = new DateTimeZone('Europe/Berlin');
        $stats_array = [
            'date' => $dateTime->setTimezone($berlinTz)->format('d.m.Y'),
            'time' => $dateTime->setTimezone($berlinTz)->format('H:i:s'),
            'power' => array_sum($stats_power),
            'temp' => $this->pmRound(array_sum($stats_temp) / count($stats_temp), true, 1),
        ];

        if ($skipped) {
            $i = count($data['Data']['QueryResults']);
            $stats_array['power'] = $stats_array['power'] / $i * ($i + $skipped);
        }
        $stats_array['power'] = $this->pmRound($stats_array['power'], true, 2);

        return $stats_array;
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round($value, $numberFormat, $maxPrecision, $this->dc->rounding_precision, $this->dc->power_threshold);
    }
}

//EOF
