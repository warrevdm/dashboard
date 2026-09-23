<?php

require_once __DIR__ . '/bootstrap.php';

class Database
{
    public static function connect(): PDO
    {
        Auth::requireLogin();

        $configPath = __DIR__ . '/../config/config.php';

        if (!file_exists($configPath)) {
            die('Databaseconfiguratie niet gevonden.');
        }

        $config = require $configPath;

        $requiredKeys = [
            'db_host',
            'db_name',
            'db_user',
            'db_pass',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $config)) {
                die('Databaseconfiguratie onvolledig: ' . $key . ' ontbreekt.');
            }
        }

        try {
            return new PDO(
                sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    $config['db_host'],
                    $config['db_name']
                ),
                $config['db_user'],
                $config['db_pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die('Databaseverbinding mislukt: ' . $e->getMessage());
        }
    }
}
