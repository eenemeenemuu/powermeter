<?php

interface DataStoreInterface
{
    /**
     * Get all readings for a given date.
     *
     * @param string $date Date in YYYY-MM-DD format
     * @return array Array of CSV lines or parsed readings
     */
    public function getReadings(string $date): array;

    /**
     * Append a reading line for a given date.
     *
     * @param string $date Date in YYYY-MM-DD format
     * @param string $csvLine CSV-formatted data line
     */
    public function appendReading(string $date, string $csvLine): void;

    /**
     * Get the latest cached stats string.
     *
     * @return string|null
     */
    public function getLatestStats(): ?string;

    /**
     * Set the latest cached stats string.
     *
     * @param string $stats CSV stats string
     */
    public function setLatestStats(string $stats): void;

    /**
     * Get all chart stats data.
     *
     * @return array [chart_stats, monthly_totals, monthly_feed_totals]
     */
    public function getChartStats(): array;

    /**
     * Save chart stats for a given date.
     *
     * @param string $date Date in YYYY-MM-DD format
     * @param string $line CSV stats line
     */
    public function saveChartStats(string $date, string $line): void;

    /**
     * Get available data file dates and metadata.
     *
     * @param string|null $requestedFile Optional file parameter for position lookup
     * @return array [files, position, file_dates]
     */
    public function getAvailableDates(?string $requestedFile = null): array;

    /**
     * Get chart details for a given resolution.
     *
     * @param int $resolution Power details resolution
     * @return array Keyed by date, values are unserialized power details
     */
    public function getChartDetails(int $resolution): array;

    /**
     * Save chart details for a given date and resolution.
     *
     * @param string $date Date in YYYY-MM-DD format
     * @param int $resolution Power details resolution
     * @param string $line Full CSV line (date,serialized_data)
     */
    public function saveChartDetails(string $date, int $resolution, string $line): void;

    /**
     * Read raw file data for a given file entry.
     *
     * @param array $fileEntry File entry from getAvailableDates [date, name]
     * @param array|null $adjacentFile Optional adjacent file for merged data
     * @return array [data_string, is_compressed]
     */
    public function readFileData(array $fileEntry, ?array $adjacentFile = null): array;

    /**
     * Compress a data file (gzip).
     *
     * @param string $fileName File name
     * @param array $lines Data lines
     * @return string Resulting file name (may have .gz appended)
     */
    public function compressFile(string $fileName, array $lines): string;

    /**
     * Get/set power array for extra logging.
     *
     * @param array|null $data If provided, saves the array; if null, reads it
     * @return array|null
     */
    public function powerArray(?array $data = null): ?array;

    /**
     * Get buffer contents for external host sync.
     *
     * @return string|null
     */
    public function getBuffer(): ?string;

    /**
     * Append to buffer file.
     *
     * @param string $line
     */
    public function appendBuffer(string $line): void;

    /**
     * Delete the buffer file.
     */
    public function deleteBuffer(): void;

    /**
     * Check if buffer file exists.
     *
     * @return bool
     */
    public function hasBuffer(): bool;
}

/**
 * Factory function to create the appropriate DataStore implementation.
 */
function createDataStore(Config $config): DataStoreInterface
{
    if ($config->data_backend === 'sqlite') {
        // Phase 2: return new SqliteDataStore($config);
        throw new RuntimeException('SQLite backend not yet implemented. Use csv backend.');
    }
    return new CsvDataStore($config);
}

//EOF
