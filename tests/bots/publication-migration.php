<?php
declare(strict_types=1);

// Exercise the real MySQL migration against an isolated legacy schema.
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
if (!is_file($root . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP bot publication migration: local MySQL configuration unavailable.\n";
    exit(0);
}
$config = require $root . '/config.php';
$db = $config['database'];
$database = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'tinycat_bot_interval_test_' . bin2hex(random_bytes(6));
$database->exec('CREATE DATABASE `' . $name . '`');
try {
    $database->exec('USE `' . $name . '`');
    $database->exec('CREATE TABLE bot_sources (id INT PRIMARY KEY, interval_minutes INT NOT NULL DEFAULT 60)');
    $database->exec('INSERT INTO bot_sources VALUES (1, 60), (2, 120)');
    $migrate = require $root . '/migrations/20261029_002_bot_publication_interval.php';
    $migrate($database);
    $rows = $database->query('SELECT * FROM bot_sources ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if (array_column($rows, 'publication_interval_minutes') !== [30, 30] || array_column($rows, 'interval_minutes') !== [60, 120]) {
        throw new RuntimeException('Migration must default legacy sources to 30 without changing polling.');
    }
    $database->exec('UPDATE bot_sources SET publication_interval_minutes = 45 WHERE id = 1');
    $database->exec('UPDATE bot_sources SET publication_interval_minutes = 0 WHERE id = 2');
    $migrate($database);
    $database->exec('INSERT INTO bot_sources (id) VALUES (3)');
    if ($database->query('SELECT publication_interval_minutes FROM bot_sources ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) !== [45, 30, 30]) {
        throw new RuntimeException('Retries must preserve configured values, repair invalid values and default new rows to 30.');
    }
    echo "PASS bot publication migration (legacy, idempotency, preservation and defaults)\n";
} finally {
    $database->exec('DROP DATABASE `' . $name . '`');
}
