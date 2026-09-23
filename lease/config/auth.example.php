<?php

// Use: php lease/scripts/configure-auth.php
// The generated auth.local.php is ignored by Git. Do not reuse the old secrets.
return [
    'access_key_hash' => 'REPLACE_WITH_PASSWORD_HASH',
    'cookie_secret' => 'REPLACE_WITH_64_CHAR_RANDOM_SECRET',
    'remember_days' => 30,
    'max_attempts' => 5,
    'lockout_seconds' => 300,
    'session_idle_seconds' => 28800,
    'session_max_seconds' => 86400,
    // Optional: absolute path to a private writable directory outside the webroot.
    // 'auth_storage_dir' => '/private/aab-lease-auth',
];
