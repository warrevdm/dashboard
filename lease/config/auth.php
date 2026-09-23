<?php

// This tracked file contains defaults only. Never put credentials here.
$config = [
    'access_key_hash' => '',
    'cookie_secret' => '',
    'remember_days' => 30,
    'max_attempts' => 5,
    'lockout_seconds' => 300,
    'session_idle_seconds' => 28800,
    'session_max_seconds' => 86400,
    'cookie_name' => 'aab_lease_remember',
    'auth_storage_dir' => __DIR__ . '/../storage/auth',
];

// Prefer an absolute path outside the document root in production.
$privatePath = getenv('AAB_LEASE_AUTH_FILE') ?: __DIR__ . '/auth.local.php';
if (is_file($privatePath)) {
    $privateConfig = require $privatePath;
    if (is_array($privateConfig)) {
        $config = array_replace($config, $privateConfig);
    }
}

return $config;
