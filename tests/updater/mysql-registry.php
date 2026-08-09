<?php
declare(strict_types=1);

use TinyCat\Update\MigrationRegistry;

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

$config = (array) config('database', []);

if (($config['driver'] ?? 'mysql') !== 'mysql') {
    echo "SKIP MySQL migration registry: a local MySQL database is required.\n";
    exit(0);
}

$host = (string) ($config['host'] ?? 'localhost');
$port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
$charset = (string) ($config['charset'] ?? 'utf8mb4');
$databaseName = 'tinycat_registry_test_' . strtolower(bin2hex(random_bytes(5)));
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

if (preg_match('/^tinycat_registry_test_[a-f0-9]{10}$/', $databaseName) !== 1) {
    throw new RuntimeException('Unsafe temporary database name.');
}

$server = new PDO(
    sprintf('mysql:host=%s%s;charset=%s', $host, $port, $charset),
    (string) ($config['user'] ?? ''),
    (string) ($config['password'] ?? ''),
    $options
);
$created = false;

try {
    try {
        $server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    } catch (PDOException $exception) {
        echo 'SKIP MySQL migration registry: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );
    Core::setDb($database);
    $database->exec('CREATE TABLE user_profile_links (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $removeProfileLinks = require dirname(__DIR__, 2) . '/migrations/20260809_001_remove_user_profile_links.php';

    if (!is_callable($removeProfileLinks)) {
        throw new RuntimeException('The profile links removal migration is not callable.');
    }

    $removeProfileLinks($database);
    if ((int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profile_links'")->fetchColumn() !== 0) {
        throw new RuntimeException('The profile links removal migration did not drop its table.');
    }
    run(
        'CREATE TABLE schema_migrations (
            version VARCHAR(80) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    insert('schema_migrations', ['version' => '20260731_email_analytics_ip_limits']);
    insert('schema_migrations', ['version' => '20260801_bot_sources']);

    try {
        MigrationRegistry::ensure();
    } catch (RuntimeException $exception) {
        if (str_contains($exception->getMessage(), 'Update TinyCat to 1.0.14')) {
            echo "PASS MySQL outdated migration registry rejected by 2.x baseline.\n";
            return;
        }
        throw $exception;
    }
    throw new RuntimeException('The outdated MySQL migration registry was accepted.');
} finally {
    if ($created && preg_match('/^tinycat_registry_test_[a-f0-9]{10}$/', $databaseName) === 1) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
