<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/functions.php';

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
    run(
        'CREATE TABLE schema_migrations (
            version VARCHAR(80) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    insert('schema_migrations', ['version' => '20260731_email_analytics_ip_limits']);
    insert('schema_migrations', ['version' => '20260801_bot_sources']);

    $method = new ReflectionMethod(Updater::class, 'ensureMigrationTable');
    $method->invoke(null);
    $method->invoke(null);

    $columns = array_column(all('SHOW COLUMNS FROM schema_migrations'), null, 'Field');
    $primary = array_map(
        static fn (array $index): string => (string) ($index['COLUMN_NAME'] ?? ''),
        all(
            "SELECT COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'schema_migrations'
               AND INDEX_NAME = 'PRIMARY'
             ORDER BY SEQ_IN_INDEX"
        )
    );
    $history = all('SELECT migration, version, checksum FROM schema_migrations ORDER BY migration');

    if (!isset($columns['migration'], $columns['version'], $columns['checksum'], $columns['applied_at'])) {
        throw new RuntimeException('The upgraded registry is missing required columns.');
    }

    if ($primary !== ['migration']) {
        throw new RuntimeException('The upgraded registry primary key is not migration.');
    }

    if (count($history) !== 2 || ($history[0]['migration'] ?? '') !== '20260731_email_analytics_ip_limits') {
        throw new RuntimeException('Legacy migration history was not preserved.');
    }

    foreach ($history as $row) {
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($row['checksum'] ?? '')) !== 1) {
            throw new RuntimeException('A legacy migration checksum was not backfilled.');
        }
    }

    insert('schema_migrations', [
        'migration' => '20260805_001_user_relations',
        'version' => '1.0.7',
        'checksum' => str_repeat('c', 64),
        'applied_at' => date_db(),
    ]);

    if ((int) val('SELECT COUNT(*) FROM schema_migrations') !== 3) {
        throw new RuntimeException('The upgraded registry rejected a new migration.');
    }

    echo "PASS MySQL legacy migration registry upgrade: history preserved and new migrations accepted.\n";
} finally {
    if ($created && preg_match('/^tinycat_registry_test_[a-f0-9]{10}$/', $databaseName) === 1) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
