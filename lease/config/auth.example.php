<?php

return [
    // Genereer met:
    // C:\xampp\php\php.exe -r "echo password_hash('JOUW_TOEGANGSSLEUTEL', PASSWORD_DEFAULT), PHP_EOL;"
    'access_key_hash' => 'REPLACE_WITH_PASSWORD_HASH',

    // Genereer met:
    // C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'cookie_secret' => 'REPLACE_WITH_64_CHAR_RANDOM_SECRET',

    // Hoe lang 'ingelogd blijven' geldig blijft.
    'remember_days' => 30,

    // Eenvoudige sessiegebonden brute-force bescherming.
    'max_attempts' => 5,
    'lockout_seconds' => 300,

    // Naam van de blijvende login-cookie.
    'cookie_name' => 'aab_lease_remember',
];
