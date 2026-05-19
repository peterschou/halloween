<?php
// Configuration for The Scare Path application.

declare(strict_types=1);

$DB_CONFIG = [
    'host' => getenv('DB_HOST') ?: 'db',
    'name' => getenv('DB_NAME') ?: 'scarepath',
    'user' => getenv('DB_USER') ?: 'scarepath_user',
    'pass' => getenv('DB_PASS') ?: 'secret',
    'charset' => 'utf8mb4',
];

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
