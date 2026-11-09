<?php
// Creates the PDO database connection. All other files call db() to get it.
// The connection is created only once per request and then reused.

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $configFile = __DIR__ . '/config.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException('Missing backend/config/config.php (copy config.example.php).');
        }
        $config = require $configFile;

        $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";

        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // SQL errors throw exceptions
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // rows as ['column' => value]
            PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements
        ]);

        // Use the same time zone in MySQL as in PHP, so NOW() and PHP dates match.
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }

    return $pdo;
}
