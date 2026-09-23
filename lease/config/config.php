<?php

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$isLocal = in_array($host, [
    'localhost',
    '127.0.0.1',
], true);

if ($isLocal) {
    return require __DIR__ . '/config.local.php';
}

return require __DIR__ . '/config.production.php';
