<?php

/** Connection factory shared by the authenticated web app and private CLI jobs. */
final class DatabaseConnection
{
    public static function open(string $configPath): PDO
    {
        if (!is_file($configPath)) {
            throw new RuntimeException('Databaseconfiguratie niet gevonden.');
        }
        $config = require $configPath;
        if (!is_array($config)) {
            throw new RuntimeException('Databaseconfiguratie is ongeldig.');
        }
        foreach (['db_host', 'db_name', 'db_user', 'db_pass'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException('Databaseconfiguratie is onvolledig.');
            }
        }
        return new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
            $config['db_user'],
            $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
}
