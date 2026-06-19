<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$result = [
    'ok' => false,
    'php_version' => PHP_VERSION,
    'paths' => [
        'root' => $root,
        'config_php_exists' => is_file($root . '/config.php'),
        'src_exists' => is_dir($root . '/src'),
        'public_exists' => is_dir($root . '/public'),
    ],
    'extensions' => [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'curl' => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
    ],
];

try {
    $configPath = is_file($root . '/config.php') ? $root . '/config.php' : $root . '/config.example.php';
    $config = require $configPath;

    $db = $config['db'] ?? [];
    $result['config'] = [
        'loaded' => basename($configPath),
        'db_host_set' => !empty($db['host']),
        'db_database_set' => !empty($db['database']),
        'db_user_set' => !empty($db['user']),
        'db_password_set' => array_key_exists('password', $db) && (string) $db['password'] !== '',
        'public_api_url' => $config['public_api_url'] ?? null,
        'frontend_origins_count' => count($config['frontend_origins'] ?? []),
    ];

    if (!$result['extensions']['pdo_mysql']) {
        throw new RuntimeException('pdo_mysql extension is not enabled');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'] ?? '',
        $db['port'] ?? '3306',
        $db['database'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $result['database'] = [
        'connected' => true,
        'teams_count' => (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn(),
        'players_count' => (int) $pdo->query('SELECT COUNT(*) FROM players')->fetchColumn(),
    ];
    $result['ok'] = true;
} catch (Throwable $e) {
    http_response_code(500);
    $result['error'] = [
        'type' => $e::class,
        'message' => $e->getMessage(),
    ];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
