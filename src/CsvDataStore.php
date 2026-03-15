<?php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/DataStoreInterface.php';
require_once __DIR__ . '/Helpers.php';

class CsvDataStore implements DataStoreInterface
{
    private $config;
    private $dir;
    private $deviceId;

    /**
     * @param Config $config
     * @param string|null $deviceId Optional device ID for multi-device mode.
     *                              When set and not 'default', uses data/{device_id}/ subdirectory.
     */
    public function __construct(Config $config, ?string $deviceId = null)
    {
        $this->config = $config;
        $this->deviceId = $deviceId;

        if ($deviceId && $deviceId !== 'default') {
            $this->dir = $config->log_file_dir . $deviceId . '/';
        } else {
            $this->dir = $config->log_file_dir;
        }
    }

    /**
     * Ensure the data directory exists.
     */
    public function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    public function getReadings(string $date): array
    {
        $lines = [];

        // Try plain CSV first, then gzipped
        $path = $this->dir . $date . '.csv';
        $pathGz = $this->dir . $date . '.csv.gz';

        if (file_exists($path)) {
            $content = file_get_contents($path);
            $lines = array_filter(explode("\n", trim($content)), 'strlen');
        } elseif (file_exists($pathGz)) {
            $content = gzdecode(file_get_contents($pathGz));
            $lines = array_filter(explode("\n", trim($content)), 'strlen');
        }

        return $lines;
    }

    public function appendReading(string $date, string $csvLine): void
    {
        $path = $this->dir . $date . '.csv';
        file_put_contents($path, $csvLine . "\n", FILE_APPEND);
    }

    public function getLatestStats(): ?string
    {
        $path = $this->dir . 'stats.txt';
        if (file_exists($path)) {
            $content = file_get_contents($path);
            return $content !== false ? $content : null;
        }
        return null;
    }

    public function setLatestStats(string $stats): void
    {
        file_put_contents($this->dir . 'stats.txt', $stats);
    }

    public function getChartStats(): array
    {
        $chartStats = [];
        $chartStatsMonth = [];
        $chartStatsMonthFeed = [];

        $path = $this->dir . 'chart_stats.csv';
        if (!file_exists($path)) {
            return [$chartStats, $chartStatsMonth, $chartStatsMonthFeed];
        }

        foreach (explode("\n", file_get_contents($path)) as $line) {
            $parts = explode(',', $line);
            if ($parts[0]) {
                $chartStats[$parts[0]] = $parts;
                $dateParts = explode('-', $parts[0]);
                $chartStatsMonth[$dateParts[0]][$dateParts[1]] += $parts[1];
                if (isset($parts[6])) {
                    $chartStatsMonthFeed[$dateParts[0]][$dateParts[1]] += $parts[6];
                }
            }
        }

        return [$chartStats, $chartStatsMonth, $chartStatsMonthFeed];
    }

    public function saveChartStats(string $date, string $line): void
    {
        $file = 'chart_stats.csv';
        $path = $this->dir . $file;
        $save = true;

        if (file_exists($path)) {
            foreach (explode("\n", file_get_contents($path)) as $existingLine) {
                $parts = explode(',', $existingLine);
                if ($parts[0] == $date) {
                    $existingLine .= "\n";
                    if ($existingLine != $line) {
                        $contents = file_get_contents($path);
                        $contents = str_replace($existingLine, '', $contents);
                        file_put_contents($path, $contents);
                    } else {
                        $save = false;
                    }
                    break;
                }
            }
        }

        if ($save) {
            file_put_contents($path, $line, FILE_APPEND);
        }
    }

    public function getAvailableDates(?string $requestedFile = null): array
    {
        $i = 0;
        $pos = false;
        $files = [];
        $fileDates = [];

        if (!is_dir($this->dir)) {
            return [$files, $pos, $fileDates];
        }

        foreach (scandir($this->dir, SCANDIR_SORT_DESCENDING) as $file) {
            if ($file == '.' || $file == '..' || $file == 'stats.txt' || $file == 'chart_stats.csv' || substr($file, 0, 14) == 'chart_details_' || $file == 'buffer.txt' || $file == 'powermeter.db' || $file == 'anker_token.json' || $file == 'power_array') {
                continue;
            }
            if ($requestedFile !== null && ($file == $requestedFile || $file == $requestedFile . '.csv' || $file == $requestedFile . '.csv.gz' || $file == $requestedFile . '.gz')) {
                $pos = $i;
            }
            $i++;
            $files[] = ['date' => substr($file, 0, strpos($file, '.')), 'name' => $file];
        }

        foreach ($files as $file) {
            $fileDates[] = $file['date'];
        }
        $fileDates = array_unique($fileDates);

        return [$files, $pos, $fileDates];
    }

    public function getChartDetails(int $resolution): array
    {
        $details = [];
        $path = $this->dir . 'chart_details_' . $resolution . '.csv';

        if (!file_exists($path)) {
            return $details;
        }

        foreach (explode("\n", file_get_contents($path)) as $line) {
            $parts = explode(',', $line);
            if ($parts[0]) {
                $details[$parts[0]] = unserialize(substr($line, strpos($line, ',') + 1));
            }
        }

        return $details;
    }

    public function saveChartDetails(string $date, int $resolution, string $line): void
    {
        $file = 'chart_details_' . $resolution . '.csv';
        $path = $this->dir . $file;
        $save = true;

        if (file_exists($path)) {
            foreach (explode("\n", file_get_contents($path)) as $existingLine) {
                $parts = explode(',', $existingLine);
                if ($parts[0] == $date) {
                    $existingLine .= "\n";
                    if ($existingLine != $line) {
                        $contents = file_get_contents($path);
                        $contents = str_replace($existingLine, '', $contents);
                        file_put_contents($path, $contents);
                    } else {
                        $save = false;
                    }
                    break;
                }
            }
        }

        if ($save) {
            file_put_contents($path, $line, FILE_APPEND);
        }
    }

    public function readFileData(array $fileEntry, ?array $adjacentFile = null): array
    {
        $data = file_get_contents($this->dir . $fileEntry['name']);
        $isCompressed = false;

        if (strpos($fileEntry['name'], '.gz') !== false) {
            $isCompressed = true;
            $data = gzdecode($data);
        } elseif ($adjacentFile !== null && $fileEntry['date'] == $adjacentFile['date']) {
            $data2 = file_get_contents($this->dir . $adjacentFile['name']);
            $data .= gzdecode($data2);
        }

        return [trim($data), $isCompressed];
    }

    public function compressFile(string $fileName, array $lines): string
    {
        if (function_exists('gzencode')) {
            $data = implode("\n", $lines);
            $gzdata = gzencode($data, 7);
            if ($gzdata) {
                if (file_put_contents($this->dir . $fileName . '.gz', $gzdata)) {
                    if (unlink($this->dir . $fileName)) {
                        return $fileName . '.gz';
                    }
                }
            }
        }
        return $fileName;
    }

    public function powerArray(?array $data = null): ?array
    {
        $path = $this->dir . 'power_array';
        if ($data !== null) {
            file_put_contents($path, json_encode($data));
            return $data;
        }
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true) ?: [];
        }
        return [];
    }

    public function getBuffer(): ?string
    {
        $path = $this->dir . 'buffer.txt';
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return null;
    }

    public function appendBuffer(string $line): void
    {
        file_put_contents($this->dir . 'buffer.txt', $line . "\n", FILE_APPEND);
    }

    public function deleteBuffer(): void
    {
        $path = $this->dir . 'buffer.txt';
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function hasBuffer(): bool
    {
        return file_exists($this->dir . 'buffer.txt');
    }
}

//EOF
