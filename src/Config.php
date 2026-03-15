<?php

class Config
{
    // Device settings
    public $device = 'tasmota';
    public $host = '';
    public $refresh_rate = 2;

    // Units and labels
    public $unit1 = 'W';
    public $unit1_label = '';
    public $unit1_label_in = 'Bezug';
    public $unit1_label_out = 'Einspeisung';
    public $unit2 = '°C';
    public $unit2_label = 'Temperatur';
    public $unit3 = 'W';
    public $unit3_label = 'L1';
    public $unit4 = 'W';
    public $unit4_label = 'L2';
    public $unit5 = 'W';
    public $unit5_label = 'L3';
    public $unit6 = 'W';
    public $unit6_label = 'L4';

    // Rounding
    public $rounding_precision = 0;
    public $power_threshold = 0;

    // FritzBox auth
    public $user = '';
    public $pass = '';
    public $ain = '';

    // Envertech
    public $station_id = '';

    // AhoyDTU
    public $inverter_id = 0;

    // Anker Solix
    public $anker_email = '';
    public $anker_password = '';
    public $anker_country = 'DE';
    public $anker_site_id = '';

    // Logging
    public $log_file_dir = 'data/';
    public $log_rate = 6;
    public $use_cache = false;
    public $log_extra_array = 0;

    // Chart
    public $fix_axis_y = 0;
    public $res = 5;
    public $unit2_display = false;
    public $unit2_min = false;
    public $unit2_max = false;
    public $power_details_resolution = 50;

    // External host
    public $host_external = '';
    public $host_auth_key = '';
    public $log_external_only = false;

    // Colors (RGB format: 'rrr, ggg, bbb')
    public $color1 = '109, 120, 173';
    public $color2 = '109, 120, 173';
    public $color3 = '127, 255, 0';
    public $color4 = '127, 255, 0';
    public $color5 = '200, 100, 0';
    public $color6 = '128, 64, 0';
    public $color7 = '0, 0, 0';
    public $color8 = '128, 128, 128';
    public $color9 = '0, 64, 128';

    // Multi-device
    public $devices = [];

    // Gesamt groups: configurable combined views
    // Each entry: ['id' => 'gesamt', 'label' => 'Gesamt', 'devices' => ['anker', 'ahoy1']]
    // If empty, one default group "gesamt" with all devices is created automatically
    public $gesamt_groups = [];

    // Virtual totals
    public $virtual_totals = [];

    // Data backend (for Phase 2)
    public $data_backend = 'csv';

    // Backward compatibility
    public $produce_consume = '';
    public $display_temp = false;

    private static $instance = null;

    /**
     * Map of config property names to PM_* environment variable names.
     */
    private static $envMap = [
        'device' => 'PM_DEVICE',
        'host' => 'PM_HOST',
        'refresh_rate' => 'PM_REFRESH_RATE',
        'unit1' => 'PM_UNIT1',
        'unit1_label' => 'PM_UNIT1_LABEL',
        'unit1_label_in' => 'PM_UNIT1_LABEL_IN',
        'unit1_label_out' => 'PM_UNIT1_LABEL_OUT',
        'unit2' => 'PM_UNIT2',
        'unit2_label' => 'PM_UNIT2_LABEL',
        'unit3' => 'PM_UNIT3',
        'unit3_label' => 'PM_UNIT3_LABEL',
        'unit4' => 'PM_UNIT4',
        'unit4_label' => 'PM_UNIT4_LABEL',
        'unit5' => 'PM_UNIT5',
        'unit5_label' => 'PM_UNIT5_LABEL',
        'unit6' => 'PM_UNIT6',
        'unit6_label' => 'PM_UNIT6_LABEL',
        'rounding_precision' => 'PM_ROUNDING_PRECISION',
        'power_threshold' => 'PM_POWER_THRESHOLD',
        'user' => 'PM_USER',
        'pass' => 'PM_PASS',
        'ain' => 'PM_AIN',
        'station_id' => 'PM_STATION_ID',
        'inverter_id' => 'PM_INVERTER_ID',
        'anker_email' => 'PM_ANKER_EMAIL',
        'anker_password' => 'PM_ANKER_PASSWORD',
        'anker_country' => 'PM_ANKER_COUNTRY',
        'anker_site_id' => 'PM_ANKER_SITE_ID',
        'log_file_dir' => 'PM_LOG_FILE_DIR',
        'log_rate' => 'PM_LOG_RATE',
        'use_cache' => 'PM_USE_CACHE',
        'log_extra_array' => 'PM_LOG_EXTRA_ARRAY',
        'fix_axis_y' => 'PM_FIX_AXIS_Y',
        'res' => 'PM_RES',
        'unit2_display' => 'PM_UNIT2_DISPLAY',
        'unit2_min' => 'PM_UNIT2_MIN',
        'unit2_max' => 'PM_UNIT2_MAX',
        'power_details_resolution' => 'PM_POWER_DETAILS_RESOLUTION',
        'host_external' => 'PM_HOST_EXTERNAL',
        'host_auth_key' => 'PM_HOST_AUTH_KEY',
        'log_external_only' => 'PM_LOG_EXTERNAL_ONLY',
        'color1' => 'PM_COLOR1',
        'color2' => 'PM_COLOR2',
        'color3' => 'PM_COLOR3',
        'color4' => 'PM_COLOR4',
        'color5' => 'PM_COLOR5',
        'color6' => 'PM_COLOR6',
        'color7' => 'PM_COLOR7',
        'color8' => 'PM_COLOR8',
        'color9' => 'PM_COLOR9',
        'data_backend' => 'PM_DATA_BACKEND',
    ];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load configuration from config.inc.php file.
     * Variables defined in the file override defaults.
     */
    public function loadFromFile(string $path): self
    {
        if (!file_exists($path)) {
            return $this;
        }

        // Extract variables from config file into a local scope
        $vars = [];
        $configContent = file_get_contents($path);
        // Use a temporary scope to avoid polluting globals
        $extractVars = function ($filePath) {
            // Suppress undefined variable notices from the config file
            @include $filePath;
            return get_defined_vars();
        };
        $vars = $extractVars($path);

        foreach ($vars as $key => $value) {
            if (property_exists($this, $key) && $value !== '' && $value !== null) {
                $this->$key = $value;
            }
        }

        // Handle backward compatibility
        if (!$this->unit1_label) {
            $this->unit1_label = $this->produce_consume ?: 'Leistung';
        }
        if ($this->display_temp && !$this->unit2_display) {
            $this->unit2_display = $this->display_temp;
        }

        return $this;
    }

    /**
     * Load configuration from PM_* environment variables.
     * Env vars override file-loaded values.
     */
    public function loadFromEnv(): self
    {
        foreach (self::$envMap as $prop => $envName) {
            $value = getenv($envName);
            if ($value !== false && $value !== '') {
                // Type-cast based on the default value type
                $default = $this->$prop;
                if (is_bool($default)) {
                    $this->$prop = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } elseif (is_int($default)) {
                    $this->$prop = (int) $value;
                } elseif (is_float($default)) {
                    $this->$prop = (float) $value;
                } else {
                    $this->$prop = $value;
                }
            }
        }

        // Ensure unit1_label has a value
        if (!$this->unit1_label) {
            $this->unit1_label = 'Leistung';
        }

        return $this;
    }

    /**
     * Load from file first, then overlay env vars.
     */
    public static function load(string $configFilePath = null): self
    {
        $instance = self::getInstance();
        if ($configFilePath) {
            $instance->loadFromFile($configFilePath);
        }
        $instance->loadFromEnv();
        return $instance;
    }

    /**
     * Export globals for backward compatibility with legacy PHP pages.
     * Sets global variables matching config.inc.php variable names.
     */
    public function exportGlobals(): void
    {
        $props = get_object_vars($this);
        foreach ($props as $key => $value) {
            $GLOBALS[$key] = $value;
        }
    }

    /**
     * Get device configurations for multi-device mode.
     * Returns array of ['id' => string, 'type' => string, 'config' => DriverConfig].
     * Single-device mode returns one entry with id 'default'.
     */
    public function getDeviceConfigs(): array
    {
        if (!empty($this->devices)) {
            $configs = [];
            foreach ($this->devices as $entry) {
                $id = $entry['id'] ?? 'device_' . count($configs);
                $type = $entry['device'] ?? $this->device;
                $dc = DriverConfig::fromArray($entry, $this);
                $configs[] = ['id' => $id, 'type' => $type, 'config' => $dc];
            }
            return $configs;
        }

        // Single-device fallback
        return [
            ['id' => 'default', 'type' => $this->device, 'config' => DriverConfig::fromConfig($this)],
        ];
    }

    /**
     * Check if running in multi-device mode.
     */
    public function isMultiDevice(): bool
    {
        return !empty($this->devices);
    }

    /**
     * Get resolved gesamt groups.
     * If none configured, returns one default group containing all device IDs.
     */
    public function getGesamtGroups(): array
    {
        if (!empty($this->gesamt_groups)) {
            return $this->gesamt_groups;
        }
        // Default: one "gesamt" group with all devices
        $ids = [];
        foreach ($this->devices as $entry) {
            $ids[] = $entry['id'] ?? 'device_' . count($ids);
        }
        return [['id' => 'gesamt', 'label' => 'Gesamt', 'devices' => $ids]];
    }

    /**
     * Find a gesamt group by ID, or null if not found.
     */
    public function getGesamtGroup(string $groupId): ?array
    {
        foreach ($this->getGesamtGroups() as $group) {
            if ($group['id'] === $groupId) {
                return $group;
            }
        }
        return null;
    }

    /**
     * Check if a given device parameter refers to a gesamt group.
     */
    public function isGesamtGroup(string $deviceParam): bool
    {
        return $this->getGesamtGroup($deviceParam) !== null;
    }

    /**
     * Return a public-safe array (excludes secrets).
     */
    public function toPublicArray(): array
    {
        return [
            'device' => $this->device,
            'refresh_rate' => $this->refresh_rate,
            'unit1' => $this->unit1,
            'unit1_label' => $this->unit1_label,
            'unit1_label_in' => $this->unit1_label_in,
            'unit1_label_out' => $this->unit1_label_out,
            'unit2' => $this->unit2,
            'unit2_label' => $this->unit2_label,
            'unit2_display' => $this->unit2_display,
            'unit2_min' => $this->unit2_min,
            'unit2_max' => $this->unit2_max,
            'unit3' => $this->unit3,
            'unit3_label' => $this->unit3_label,
            'unit4' => $this->unit4,
            'unit4_label' => $this->unit4_label,
            'unit5' => $this->unit5,
            'unit5_label' => $this->unit5_label,
            'unit6' => $this->unit6,
            'unit6_label' => $this->unit6_label,
            'fix_axis_y' => $this->fix_axis_y,
            'res' => $this->res,
            'power_details_resolution' => $this->power_details_resolution,
            'color1' => $this->color1,
            'color2' => $this->color2,
            'color3' => $this->color3,
            'color4' => $this->color4,
            'color5' => $this->color5,
            'color6' => $this->color6,
            'color7' => $this->color7,
            'color8' => $this->color8,
            'color9' => $this->color9,
            'data_backend' => $this->data_backend,
        ];
    }
}

//EOF
