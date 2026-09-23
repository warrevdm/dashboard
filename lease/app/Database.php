<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DatabaseConnection.php';

class Database
{
    public static function connect(): PDO
    {
        Auth::requireLogin();
        try {
            return DatabaseConnection::open(__DIR__ . '/../config/config.php');
        } catch (Throwable $error) {
            http_response_code(503);
            exit('De databank is tijdelijk niet beschikbaar. Neem contact op met de beheerder.');
        }
    }
}
