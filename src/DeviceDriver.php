<?php

require_once __DIR__ . '/autoload.php';

/**
 * Thin wrapper for backward compatibility.
 * Delegates to the appropriate driver via DriverFactory.
 */
class DeviceDriver
{
    private $config;
    private DeviceDriverInterface $driver;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->driver = DriverFactory::createFromConfig($config);
    }

    /**
     * Query the configured device and return stats array.
     *
     * @return array Stats array with keys: date, time, power, [temp], [emeters]
     *               Or error array: ['error', 'message']
     */
    public function getStats(): array
    {
        try {
            return $this->driver->getStats();
        } catch (RuntimeException $e) {
            return ['error', $e->getMessage()];
        }
    }
}

//EOF
