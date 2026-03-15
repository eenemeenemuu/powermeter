<?php

/**
 * Value object holding per-device settings.
 * Built from Config defaults + per-device overrides.
 */
class DriverConfig
{
    public string $host = '';
    public string $user = '';
    public string $pass = '';
    public string $ain = '';
    public string $station_id = '';
    public int $inverter_id = 0;
    public string $anker_email = '';
    public string $anker_password = '';
    public string $anker_country = 'DE';
    public string $anker_site_id = '';
    public string $log_file_dir = 'data/';
    public int $rounding_precision = 0;
    public float $power_threshold = 0;

    /**
     * Build a DriverConfig from a Config instance (single-device mode).
     */
    public static function fromConfig(Config $config): self
    {
        $dc = new self();
        $dc->host = $config->host;
        $dc->user = $config->user;
        $dc->pass = $config->pass;
        $dc->ain = $config->ain;
        $dc->station_id = $config->station_id;
        $dc->inverter_id = $config->inverter_id;
        $dc->anker_email = $config->anker_email;
        $dc->anker_password = $config->anker_password;
        $dc->anker_country = $config->anker_country;
        $dc->anker_site_id = $config->anker_site_id;
        $dc->log_file_dir = $config->log_file_dir;
        $dc->rounding_precision = $config->rounding_precision;
        $dc->power_threshold = (float) $config->power_threshold;
        return $dc;
    }

    /**
     * Build a DriverConfig from a $devices array entry + Config defaults.
     */
    public static function fromArray(array $entry, Config $config): self
    {
        $dc = self::fromConfig($config);
        // Override with per-device settings
        foreach (['host', 'user', 'pass', 'ain', 'station_id', 'inverter_id',
                   'anker_email', 'anker_password', 'anker_country', 'anker_site_id'] as $key) {
            if (isset($entry[$key])) {
                $dc->$key = $entry[$key];
            }
        }
        return $dc;
    }
}

//EOF
