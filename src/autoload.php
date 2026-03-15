<?php

if (defined('PM_AUTOLOAD_LOADED')) return;
define('PM_AUTOLOAD_LOADED', true);

// Load files containing standalone functions (not triggered by class autoloading)
require_once __DIR__ . '/DataStoreInterface.php';

spl_autoload_register(function ($class) {
    static $classMap = [
        // Core classes
        'Config' => '/Config.php',
        'Helpers' => '/Helpers.php',
        'DeviceDriver' => '/DeviceDriver.php',
        'DataStoreInterface' => '/DataStoreInterface.php',
        'CsvDataStore' => '/CsvDataStore.php',
        'StatsCalculator' => '/StatsCalculator.php',
        'DeviceManager' => '/DeviceManager.php',

        // Driver classes
        'DeviceDriverInterface' => '/Drivers/DeviceDriverInterface.php',
        'DriverConfig' => '/Drivers/DriverConfig.php',
        'DriverFactory' => '/Drivers/DriverFactory.php',
        'FritzboxDriver' => '/Drivers/FritzboxDriver.php',
        'TasmotaDriver' => '/Drivers/TasmotaDriver.php',
        'Shelly3emDriver' => '/Drivers/Shelly3emDriver.php',
        'ShellyGen2Driver' => '/Drivers/ShellyGen2Driver.php',
        'ShellyDriver' => '/Drivers/ShellyDriver.php',
        'EnvtecDriver' => '/Drivers/EnvtecDriver.php',
        'AhoydtuDriver' => '/Drivers/AhoydtuDriver.php',
        'EspEpeverDriver' => '/Drivers/EspEpeverDriver.php',
        'AnkerSolixDriver' => '/Drivers/AnkerSolixDriver.php',
    ];

    if (isset($classMap[$class])) {
        require_once __DIR__ . $classMap[$class];
    }
});

//EOF
