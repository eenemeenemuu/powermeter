<?php

class DriverFactory
{
    /**
     * Map of device type strings to driver class names.
     */
    private static $driverMap = [
        'fritzbox' => 'FritzboxDriver',
        'tasmota' => 'TasmotaDriver',
        'shelly3em' => 'Shelly3emDriver',
        'shelly_gen2' => 'ShellyGen2Driver',
        'shelly' => 'ShellyDriver',
        'envtec' => 'EnvtecDriver',
        'ahoydtu' => 'AhoydtuDriver',
        'esp-epever-controller' => 'EspEpeverDriver',
        'anker_solix' => 'AnkerSolixDriver',
    ];

    /**
     * Create a driver instance for the given device type.
     */
    public static function create(string $type, DriverConfig $dc): DeviceDriverInterface
    {
        if (!isset(self::$driverMap[$type])) {
            throw new RuntimeException("Unknown device type: {$type}");
        }

        $class = self::$driverMap[$type];
        return new $class($dc);
    }

    /**
     * Create a driver from a Config instance (single-device convenience).
     */
    public static function createFromConfig(Config $config): DeviceDriverInterface
    {
        $dc = DriverConfig::fromConfig($config);
        return self::create($config->device, $dc);
    }

    /**
     * Return all supported device type strings.
     */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::$driverMap);
    }
}

//EOF
