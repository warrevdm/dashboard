<?php

// Copy to config.local.php or config.production.php and enter private credentials.
// Keep actual database credentials out of Git.
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'lease_import_manager',
    'db_user' => 'lease_app',
    'db_pass' => 'REPLACE_WITH_DATABASE_PASSWORD',
];
