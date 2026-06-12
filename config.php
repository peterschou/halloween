<?php
// Configuration for The Scare Path application.

declare(strict_types=1);

$DB_CONFIG = [
    'host' => 'localhost',
    'name' => 'scarepath',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
    'prefix' => 'sp_',
];

// Load server-specific configuration (credentials and prefix)
if (file_exists(__DIR__ . '/db_credentials.php')) {
    $overrides = require __DIR__ . '/db_credentials.php';
    $DB_CONFIG = array_merge($DB_CONFIG, $overrides);
}

const SITE_TITLE = 'The Scare Path';

function createPDO(array $config): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['name'], $config['charset']);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $config['user'], $config['pass'], $options);
}
