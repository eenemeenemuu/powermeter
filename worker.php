<?php

/**
 * FrankenPHP Worker Mode Entry Point.
 *
 * This file is loaded ONCE when the worker boots. Config, autoloader,
 * and data store objects persist in memory across requests.
 * Each request is handled inside the frankenphp_handle_request() loop.
 *
 * Swow (if installed) hooks into PHP's stream/curl at the extension level,
 * so all file_get_contents('http://...') and curl_exec() calls in driver
 * code automatically yield to other coroutines during network I/O.
 */

// Boot phase: runs once
require_once __DIR__ . '/src/autoload.php';

$configFile = __DIR__ . '/config.inc.php';
$config = Config::load(file_exists($configFile) ? $configFile : null);

// Pre-create a data store for the default device (single-device mode)
$dataStore = createDataStore($config);

// Worker loop: handles each HTTP request
$handler = function () use ($config, $dataStore) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH);

    // Route to the appropriate legacy PHP file or API
    if (strpos($path, '/api/') === 0 || $path === '/api') {
        // API requests: include the API router
        require __DIR__ . '/api/index.php';
    } elseif ($path === '/log.php' || $path === '/log') {
        require __DIR__ . '/log.php';
    } elseif ($path === '/chart.php' || $path === '/chart') {
        require __DIR__ . '/chart.php';
    } elseif ($path === '/overview.php' || $path === '/overview') {
        require __DIR__ . '/overview.php';
    } elseif ($path === '/' || $path === '/index.php') {
        require __DIR__ . '/index.php';
    } else {
        // Static files are handled by FrankenPHP/Caddy automatically
        // If we get here, it's a 404
        http_response_code(404);
        echo '404 Not Found';
    }
};

// FrankenPHP worker loop
while (frankenphp_handle_request($handler)) {
    // Cleanup between requests if needed
    // Config and data store persist (that's the point of worker mode)
}

//EOF
