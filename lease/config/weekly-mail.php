<?php

// Defaults only. Recipients and SMTP credentials belong in the private file.
$config = [
    'enabled' => false,
    'send_hour' => 9, // Tuesday, Europe/Brussels; hour 0 through 23.
    'recipients' => [],
    'from_address' => '',
    'from_name' => 'Aerts Action Bike',
    'base_url' => 'https://aertsactionbike.cc/lease/public',
    'state_directory' => __DIR__ . '/../storage/weekly-mail',
    'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => ''],
];
$privatePath = getenv('AAB_LEASE_WEEKLY_MAIL_FILE') ?: __DIR__ . '/weekly-mail.local.php';
if (is_file($privatePath)) {
    $privateConfig = require $privatePath;
    if (!is_array($privateConfig)) {
        throw new RuntimeException('De weekmailconfiguratie is ongeldig.');
    }
    $config = array_replace_recursive($config, $privateConfig);
}
return $config;
