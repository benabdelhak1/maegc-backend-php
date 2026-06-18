<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Maegc\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$configPath = dirname(__DIR__) . '/config.php';
$examplePath = dirname(__DIR__) . '/config.example.php';
$config = require (is_file($configPath) ? $configPath : $examplePath);

date_default_timezone_set($config['timezone'] ?? 'UTC');
