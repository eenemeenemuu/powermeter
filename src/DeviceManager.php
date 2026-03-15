<?php

/**
 * Orchestrates querying multiple devices.
 * Supports sequential and async (Swow coroutine) modes.
 */
class DeviceManager
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Query all configured devices sequentially.
     *
     * @return array Keyed by device ID: ['id' => [...stats or error...]]
     */
    public function queryAll(): array
    {
        $results = [];
        foreach ($this->config->getDeviceConfigs() as $entry) {
            $id = $entry['id'];
            $type = $entry['type'];
            $dc = $entry['config'];

            try {
                $driver = DriverFactory::create($type, $dc);
                $results[$id] = $driver->getStats();
            } catch (RuntimeException $e) {
                $results[$id] = ['error', $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Query all devices using Swow coroutines if available, else sequential.
     *
     * When Swow is loaded, each device query runs in its own coroutine.
     * Swow hooks PHP stream functions (file_get_contents, fsockopen, etc.)
     * so drivers using those automatically yield during network I/O.
     *
     * Note: Drivers using curl are NOT parallelized by Swow — only PHP
     * stream-based I/O is hooked. The Anker driver uses stream-based HTTP.
     *
     * @return array Keyed by device ID
     */
    public function queryAllAsync(): array
    {
        if (!extension_loaded('swow') || !class_exists('Swow\Sync\WaitGroup')) {
            return $this->queryAll();
        }

        return $this->queryAllSwow();
    }

    /**
     * Run all device queries in parallel using Swow coroutines + WaitGroup.
     */
    private function queryAllSwow(): array
    {
        $results = [];
        $wg = new \Swow\Sync\WaitGroup();

        foreach ($this->config->getDeviceConfigs() as $entry) {
            $id = $entry['id'];
            $type = $entry['type'];
            $dc = $entry['config'];

            $wg->add();
            \Swow\Coroutine::run(function () use ($wg, &$results, $id, $type, $dc) {
                try {
                    $driver = DriverFactory::create($type, $dc);
                    $results[$id] = $driver->getStats();
                } catch (\Throwable $e) {
                    $results[$id] = ['error', $e->getMessage()];
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();

        return $results;
    }

    /**
     * Get the combined power from all devices.
     */
    public static function combinedPower(array $allResults): float
    {
        $total = 0;
        foreach ($allResults as $stats) {
            if (isset($stats['power']) && !isset($stats[0])) {
                $total += floatval($stats['power']);
            }
        }
        return $total;
    }
}

//EOF
