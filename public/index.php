<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$app = new Maegc\App($config);
$app->handle();
