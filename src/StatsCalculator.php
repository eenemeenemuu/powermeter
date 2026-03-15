<?php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Helpers.php';

class StatsCalculator
{
    private $config;

    // Accumulated stats
    private $first = null;
    private $last = null;
    private $peak = ['p' => 0];
    private $wh = 0;
    private $whFeed = 0;
    private $lastP = null;
    private $lastTimestamp = null;
    private $percentMin = PHP_INT_MAX;
    private $percentMax = PHP_INT_MIN;
    private $powerDetails = [];
    private $powerDetailsWh = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Process a single reading value and accumulate stats.
     *
     * @param array $value Reading with keys: h, m, s, p, [t]
     */
    public function addReading(array $value): void
    {
        $value['p'] = floatval($value['p']);

        if (!$this->first && $value['p']) {
            $this->first = $value;
        }
        if ($value['p']) {
            $this->last = $value;
        }

        $valueAbs = $value;
        $valueAbs['p'] = abs($value['p']);
        if ($valueAbs['p'] && $valueAbs['p'] > $this->peak['p']) {
            $this->peak = $valueAbs;
        }

        if ($this->config->unit2 == '%' && isset($value['t'])) {
            $this->percentMin = min($this->percentMin, $value['t']);
            $this->percentMax = max($this->percentMax, $value['t']);
        }

        if ($this->lastP !== null) {
            $limit = $this->config->device == 'envtec' ? 300 : 100;
            $now = mktime($value['h'], $value['m'], $value['s']);
            if ($now - $this->lastTimestamp < $limit) {
                $pdr = $this->config->power_details_resolution;
                if ($pdr) {
                    for ($i = 0; $i <= $this->lastP; $i += $pdr) {
                        $this->powerDetails[$i] += $now - $this->lastTimestamp;
                        $this->powerDetailsWh[$i] += ($this->lastP - $i) * ($now - $this->lastTimestamp) / 60 / 60;
                    }
                }
                if ($this->lastP < 0) {
                    $this->whFeed -= $this->lastP * ($now - $this->lastTimestamp) / 60 / 60;
                } else {
                    $this->wh += $this->lastP * ($now - $this->lastTimestamp) / 60 / 60;
                }
            }
        }

        $this->lastP = $value['p'];
        $this->lastTimestamp = mktime($value['h'], $value['m'], $value['s']);
    }

    /**
     * Get the accumulated statistics.
     *
     * @return array
     */
    public function getStats(): array
    {
        $rp = $this->config->rounding_precision;
        $pt = $this->config->power_threshold;

        $firstFormatted = $this->first
            ? str_pad($this->first['h'], 2, '0', STR_PAD_LEFT) . ':' . str_pad($this->first['m'], 2, '0', STR_PAD_LEFT) . ':' . str_pad($this->first['s'], 2, '0', STR_PAD_LEFT)
            : '';
        $lastFormatted = $this->last
            ? str_pad($this->last['h'], 2, '0', STR_PAD_LEFT) . ':' . str_pad($this->last['m'], 2, '0', STR_PAD_LEFT) . ':' . str_pad($this->last['s'], 2, '0', STR_PAD_LEFT)
            : '';
        $peakFormatted = isset($this->peak['h'])
            ? str_pad($this->peak['h'], 2, '0', STR_PAD_LEFT) . ':' . str_pad($this->peak['m'], 2, '0', STR_PAD_LEFT) . ':' . str_pad($this->peak['s'], 2, '0', STR_PAD_LEFT)
            : '';

        // Format power details durations
        $powerDetailsFormatted = [];
        foreach ($this->powerDetails as $key => $value) {
            $powerDetailsFormatted[$key] = gmdate('H:i:s', $value);
        }

        return [
            'first' => $firstFormatted,
            'last' => $lastFormatted,
            'peak_power' => Helpers::round(abs($this->peak['p']), true, 9, $rp, $pt),
            'peak_time' => $peakFormatted,
            'wh' => Helpers::round($this->wh, true, 9, $rp, $pt),
            'wh_raw' => $this->wh,
            'wh_feed' => Helpers::round($this->whFeed, true, 9, $rp, $pt),
            'wh_feed_raw' => $this->whFeed,
            'percent_min' => $this->percentMin < PHP_INT_MAX ? $this->percentMin : null,
            'percent_max' => $this->percentMax > PHP_INT_MIN ? $this->percentMax : null,
            'power_details' => $powerDetailsFormatted,
            'power_details_wh' => $this->powerDetailsWh,
        ];
    }

    /**
     * Get running Wh total (for chart data points).
     */
    public function getWhTotal(): float
    {
        return $this->wh;
    }

    /**
     * Get running Wh feed total (for chart data points).
     */
    public function getWhFeedTotal(): float
    {
        return $this->whFeed;
    }

    /**
     * Process a full day of readings and return chart-ready data.
     *
     * @param array $lines Raw CSV lines for the day
     * @param int $res Resolution in minutes (-1 for all data)
     * @param int $t1 Start hour filter
     * @param int $t2 End hour filter
     * @param bool $threePhase Whether to return 3-phase data
     * @return array Chart data structure
     */
    public function processDay(array $lines, int $res, int $t1 = 0, int $t2 = 23, bool $threePhase = false): array
    {
        $rp = $this->config->rounding_precision;
        $pt = $this->config->power_threshold;
        $pmRound = function ($value) use ($rp, $pt) {
            return Helpers::round($value, false, 9, $rp, $pt);
        };

        // Parse lines into data array
        $data = [];
        $unit2Measured = false;
        $feedMeasured = false;
        $extraData = false;

        $uniqueLines = array_unique($lines);
        sort($uniqueLines);

        foreach ($uniqueLines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $parts = explode(',', $line);
            $timeParts = explode(':', $parts[1]);

            $entry = [
                'h' => $timeParts[0],
                'm' => $timeParts[1],
                's' => $timeParts[2],
                'p' => $parts[2],
            ];

            if ($threePhase) {
                $entry['l1'] = isset($parts[4]) ? $parts[4] : 0;
                $entry['l2'] = isset($parts[5]) ? $parts[5] : 0;
                $entry['l3'] = isset($parts[6]) ? $parts[6] : 0;
                $entry['l4'] = isset($parts[7]) ? $parts[7] : 0;
            } elseif ($this->config->unit2_display && isset($parts[3])) {
                $entry['t'] = $parts[3];
            }

            if (!$extraData && isset($parts[4])) {
                $extraData = true;
            }

            $data[] = $entry;
        }

        // Build chart data points
        $dataPoints = [];
        $dataPointsT = [];
        $dataPointsWh = [];
        $dataPointsWhFeed = [];
        $dataPointsFeed = [];
        $dataPointsL1 = [];
        $dataPointsL2 = [];
        $dataPointsL3 = [];
        $dataPointsL4 = [];
        $yMax = 0;

        if ($res == -1) {
            // All raw data points
            foreach ($data as $value) {
                if ($value['h'] >= $t1 && $value['h'] <= $t2) {
                    $timeStr = $value['h'] . ':' . $value['m'] . ':' . $value['s'];
                    if ($threePhase) {
                        $dataPointsL1[] = ['x' => $timeStr, 'y' => $pmRound($value['l1'])];
                        $dataPointsL2[] = ['x' => $timeStr, 'y' => $pmRound($value['l2'])];
                        $dataPointsL3[] = ['x' => $timeStr, 'y' => $pmRound($value['l3'])];
                        $dataPointsL4[] = ['x' => $timeStr, 'y' => $pmRound($value['l4'])];
                    } else {
                        $this->addReading($value);
                        if ($value['p'] < 0) {
                            $feedMeasured = true;
                            $dataPoints[] = ['x' => $timeStr, 'y' => 0];
                            $dataPointsFeed[] = ['x' => $timeStr, 'y' => $pmRound(abs($value['p']))];
                        } else {
                            $dataPoints[] = ['x' => $timeStr, 'y' => $pmRound($value['p'])];
                            $dataPointsFeed[] = ['x' => $timeStr, 'y' => 0];
                        }
                        if (abs($value['p']) > $yMax) {
                            $yMax = abs($value['p']);
                        }
                        $dataPointsWh[] = $pmRound($this->wh);
                        $dataPointsWhFeed[] = $pmRound($this->whFeed);
                        if (isset($value['t'])) {
                            $unit2Measured = true;
                            $dataPointsT[] = $pmRound($value['t']);
                        }
                    }
                }
            }
        } else {
            // Aggregated resolution
            for ($h = $t1; $h <= $t2; $h++) {
                for ($m = 0; $m < 60; $m += $res) {
                    $l1Res = [];
                    $l2Res = [];
                    $l3Res = [];
                    $l4Res = [];
                    $pRes = [];
                    $tRes = [];
                    $pResFeed = [];
                    $y = 0;
                    $yFeed = 0;

                    foreach ($data as $value) {
                        if ($value['h'] == $h && ($value['m'] >= $m && $value['m'] < $m + $res)) {
                            if ($threePhase) {
                                $l1Res[] = $value['l1'];
                                $l2Res[] = $value['l2'];
                                $l3Res[] = $value['l3'];
                                $l4Res[] = $value['l4'];
                            } else {
                                if ($value['p'] < 0) {
                                    $feedMeasured = true;
                                    $pResFeed[] = $value['p'];
                                } else {
                                    $pRes[] = $value['p'];
                                }
                                if (isset($value['t'])) {
                                    $tRes[] = $value['t'];
                                }
                                $this->addReading($value);
                            }
                        }
                    }

                    $timeStr = ($h < 10 ? '0' . $h : $h) . ':' . ($m < 10 ? '0' . $m : $m);

                    if ($threePhase) {
                        $dataPointsL1[] = ['x' => $timeStr, 'y' => count($l1Res) ? $pmRound(array_sum($l1Res) / count($l1Res)) : 0];
                        $dataPointsL2[] = ['x' => $timeStr, 'y' => count($l2Res) ? $pmRound(array_sum($l2Res) / count($l2Res)) : 0];
                        $dataPointsL3[] = ['x' => $timeStr, 'y' => count($l3Res) ? $pmRound(array_sum($l3Res) / count($l3Res)) : 0];
                        $dataPointsL4[] = ['x' => $timeStr, 'y' => count($l4Res) ? $pmRound(array_sum($l4Res) / count($l4Res)) : 0];
                    } else {
                        if (count($pRes)) {
                            $y = array_sum($pRes) / count($pRes);
                            if ($y > $yMax) {
                                $yMax = $y;
                            }
                        }
                        $dataPoints[] = ['x' => $timeStr, 'y' => $pmRound($y)];

                        if (count($pResFeed)) {
                            $feedMeasured = true;
                            $yFeed = abs(array_sum($pResFeed) / count($pResFeed));
                            if ($yFeed > $yMax) {
                                $yMax = $yFeed;
                            }
                        }
                        $dataPointsFeed[] = ['x' => $timeStr, 'y' => $pmRound($yFeed)];

                        $dataPointsWh[] = $pmRound($this->wh);
                        $dataPointsWhFeed[] = $pmRound($this->whFeed);

                        if (count($tRes)) {
                            $unit2Measured = true;
                            $dataPointsT[] = $pmRound(array_sum($tRes) / count($tRes));
                        } else {
                            $dataPointsT[] = null;
                        }
                    }
                }
            }
        }

        $stats = $this->getStats();

        return [
            'stats' => $stats,
            'data_points' => $dataPoints,
            'data_points_t' => $dataPointsT,
            'data_points_wh' => $dataPointsWh,
            'data_points_wh_feed' => $dataPointsWhFeed,
            'data_points_feed' => $dataPointsFeed,
            'data_points_l1' => $dataPointsL1,
            'data_points_l2' => $dataPointsL2,
            'data_points_l3' => $dataPointsL3,
            'data_points_l4' => $dataPointsL4,
            'y_max' => $yMax,
            'unit2_measured' => $unit2Measured,
            'feed_measured' => $feedMeasured,
            'extra_data' => $extraData,
            'reading_count' => count($data),
        ];
    }

    /**
     * Process monthly chart data from chart_stats.
     *
     * @param array $chartStats All chart stats
     * @param string $month Month in YYYY-MM format
     * @param bool $feed Whether to show feed-in data
     * @return array Monthly chart data
     */
    public function processMonth(array $chartStats, string $month, bool $feed = false): array
    {
        $index = $feed ? 6 : 1;
        $feedMeasured = $feed;

        // Initialize days of month
        $daysInMonth = [];
        for ($i = 1; $i <= 31; $i++) {
            $mm = substr($month, -2);
            if ($i > 28 && $mm == '02') continue;
            if ($i == 31 && in_array($mm, ['04', '06', '09', '11'])) continue;
            $day = $month . '-' . ($i < 10 ? '0' . $i : $i);
            $daysInMonth[$day] = null;
        }

        $months = [];
        foreach ($chartStats as $day => $data) {
            $thisMonth = substr($day, 0, 7);
            $months[] = $thisMonth;
            if ($thisMonth == $month) {
                $daysInMonth[$day] = $data[$index];
                if (!$feedMeasured && array_key_exists(6, $data)) {
                    $feedMeasured = true;
                }
            }
        }

        $months = array_unique($months);
        rsort($months);
        ksort($daysInMonth);

        $pos = false;
        foreach ($months as $key => $value) {
            if ($value == $month) {
                $pos = $key;
                break;
            }
        }

        $rp = $this->config->rounding_precision;
        $pt = $this->config->power_threshold;
        $total = Helpers::round(array_sum($daysInMonth) / 1000, false, 9, $rp, $pt);
        $maxVal = max(array_filter($daysInMonth, function ($v) { return $v !== null; }));
        $nonNullValues = array_filter($daysInMonth, 'strlen');
        $minVal = count($nonNullValues) ? min($nonNullValues) : null;

        return [
            'data' => $daysInMonth,
            'months' => $months,
            'position' => $pos,
            'total_kwh' => $total,
            'max' => $maxVal,
            'min' => $minVal,
            'feed_measured' => $feedMeasured,
        ];
    }

    /**
     * Process yearly chart data from chart_stats.
     *
     * @param array $chartStats All chart stats
     * @param string $year Year in YYYY format
     * @param bool $feed Whether to show feed-in data
     * @return array Yearly chart data
     */
    public function processYear(array $chartStats, string $year, bool $feed = false): array
    {
        $index = $feed ? 6 : 1;
        $feedMeasured = $feed;

        $monthsInYear = [];
        for ($i = 1; $i <= 12; $i++) {
            $monthsInYear[$year . '-' . ($i < 10 ? '0' . $i : $i)] = null;
        }

        $years = [];
        foreach ($chartStats as $day => $data) {
            $thisYear = substr($day, 0, 4);
            $years[] = $thisYear;
            if ($thisYear == $year) {
                $monthsInYear[substr($day, 0, 7)] += $data[$index] / 1000;
                if (!$feedMeasured && array_key_exists(6, $data)) {
                    $feedMeasured = true;
                }
            }
        }

        $years = array_unique($years);
        rsort($years);
        ksort($monthsInYear);

        $pos = false;
        foreach ($years as $key => $value) {
            if ($value == $year) {
                $pos = $key;
                break;
            }
        }

        $rp = $this->config->rounding_precision;
        $pt = $this->config->power_threshold;
        $total = Helpers::round(array_sum($monthsInYear), false, 9, $rp, $pt);

        // Round individual month values
        $rounded = [];
        foreach ($monthsInYear as $key => $value) {
            $rounded[$key] = Helpers::round($value, false, 9, $rp, $pt);
        }

        $nonNullValues = array_filter($rounded, 'strlen');

        return [
            'data' => $rounded,
            'years' => $years,
            'position' => $pos,
            'total_kwh' => $total,
            'max' => count($nonNullValues) ? max($nonNullValues) : null,
            'min' => count($nonNullValues) ? min($nonNullValues) : null,
            'feed_measured' => $feedMeasured,
        ];
    }

    /**
     * Calculate smart Y axis scaling.
     */
    public static function getYMinMax(string $minMax, $data)
    {
        $multiplier = 1;
        $floorCeil = 0;
        while (!$floorCeil) {
            foreach ([1, 2, 5] as $i) {
                if (abs($data) < $i * $multiplier) {
                    $floorCeil = $i * $multiplier / 10;
                    break;
                }
            }
            $multiplier *= 10;
        }

        if ($minMax == 'max') {
            return ceil($data / $floorCeil) * $floorCeil;
        }
        if ($minMax == 'min') {
            return floor($data / $floorCeil) * $floorCeil;
        }
        return null;
    }
}

//EOF
