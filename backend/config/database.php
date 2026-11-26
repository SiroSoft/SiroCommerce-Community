<?php

declare(strict_types=1);

$driver = strtolower((string) (getenv('DB_CONNECTION') ?: 'sqlite'));
$database = (string) (getenv('DB_DATABASE') ?: 'storage/database.sqlite');

return [
    'driver' => $driver,
    'host' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'database' => $database,
    'username' => (string) (getenv('DB_USERNAME') ?: ''),
    'password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'charset' => 'utf8mb4',
];
