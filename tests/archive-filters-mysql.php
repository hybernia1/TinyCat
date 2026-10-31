<?php
declare(strict_types=1);

if (!is_file(dirname(__DIR__) . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP MySQL archive filters: local configuration or pdo_mysql is unavailable.\n";
    exit;
}
$config = require dirname(__DIR__) . '/config.php';
$settings = $config['database'];
$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $settings['host'], $settings['port'] ?? 3306);
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO($dsn, $settings['user'], $settings['password'], $options);
$name = 'tinycat_archive_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $archiveMysqlDatabase = new PDO($dsn . ';dbname=' . $name, $settings['user'], $settings['password'], $options);
    require __DIR__ . '/archive-filters.php';
} finally {
    $archiveMysqlDatabase = null;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
