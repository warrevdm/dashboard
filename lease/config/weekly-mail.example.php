<?php

// Copy to weekly-mail.local.php. This private file must never be committed.
return [
    'enabled' => false, // Enable after configuring mail and the scheduled task.
    'send_hour' => 9, // Every Tuesday at/after 09:00, Belgian summer/winter time.
    'recipients' => ['intern@example.com'], // Internal recipients, NOT customer addresses.
    'from_address' => 'afzender@example.com',
    'from_name' => 'Aerts Action Bike',
    'base_url' => 'https://aertsactionbike.cc/lease/public',
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls', // STARTTLS: tls/587; implicit TLS: smtps/465.
        'username' => 'afzender@example.com',
        'password' => 'REPLACE_WITH_SMTP_PASSWORD',
    ],
    // Optional private writable directory outside the document root:
    // 'state_directory' => '/private/aab-lease-weekly-mail',
];
