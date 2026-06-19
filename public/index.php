<?php

declare(strict_types=1);

try {
    require __DIR__ . '/../src/bootstrap.php';

    $app = new Maegc\App($config);
    $app->handle();
} catch (Throwable $e) {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    @file_put_contents(
        $logDir . '/error.log',
        '[' . gmdate('c') . '] ' . $e::class . ': ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL . PHP_EOL,
        FILE_APPEND
    );

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Backend startup failed',
        'hint' => 'Check logs/error.log and /health.php on the backend hosting.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
