<?php
declare(strict_types=1);

use TinyCat\Extension\Lifecycle;
use TinyCat\Extension\Store;

$root = dirname(__DIR__, 2);
$extensionRoot = $root . DIRECTORY_SEPARATOR . 'Extensions' . DIRECTORY_SEPARATOR . 'Sample_Plugin';
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) $removeTree($child);
        else unlink($child);
    }
    rmdir($path);
};

if (file_exists($extensionRoot)) {
    echo "SKIP extension uninstall: Extensions/Sample_Plugin already exists.\n";
    exit(0);
}

$makeFixture = static function () use ($extensionRoot): void {
    mkdir($extensionRoot, 0777, true);
    file_put_contents($extensionRoot . DIRECTORY_SEPARATOR . 'entry.php', "<?php\n");
    file_put_contents(
        $extensionRoot . DIRECTORY_SEPARATOR . 'uninstall.php',
        "<?php\nreturn static fn (PDO \$database, array \$context): array => ['data_removed' => (\$context['mode'] ?? '') === 'purge'];\n"
    );
    file_put_contents($extensionRoot . DIRECTORY_SEPARATOR . 'extension.json', json_encode([
        'schema' => 1,
        'slug' => 'sample_plugin',
        'name' => 'Sample',
        'version' => '1.0.0',
        'requires' => ['tinycat' => '1.0.0', 'php' => '8.4.0'],
        'entry' => 'entry.php',
        'migrations' => [],
        'uninstall' => [
            'handler' => 'uninstall.php',
            'options' => [
                [
                    'id' => 'keep',
                    'labels' => ['en' => 'Keep data'],
                    'descriptions' => ['en' => 'Keep stored data.'],
                    'danger' => false,
                    'recommended' => true,
                ],
                [
                    'id' => 'purge',
                    'labels' => ['en' => 'Delete data'],
                    'descriptions' => ['en' => 'Delete stored data.'],
                    'danger' => true,
                    'recommended' => false,
                ],
            ],
        ],
        'autoload' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};

$databaseName = 'tinycat_extension_uninstall_' . strtolower(bin2hex(random_bytes(5)));
$created = false;
$backupPaths = [];

try {
    $makeFixture();
    define('TINYCAT', true);
    require_once $root . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    $config = (array) config('database', []);
    $host = (string) ($config['host'] ?? 'localhost');
    $port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
    $charset = (string) ($config['charset'] ?? 'utf8mb4');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $server = new PDO(
        sprintf('mysql:host=%s%s;charset=%s', $host, $port, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );

    try {
        $server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    } catch (PDOException $exception) {
        echo 'SKIP extension uninstall: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        return;
    }

    $database = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );
    Core::setDb($database);
    $database->exec(
        "CREATE TABLE settings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(120) NOT NULL,
            setting_group VARCHAR(60) NOT NULL DEFAULT 'general',
            setting_value LONGTEXT NULL,
            setting_type VARCHAR(20) NOT NULL DEFAULT 'string',
            autoload TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY settings_key_unique (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $database->exec(
        "CREATE TABLE schema_migrations (
            migration VARCHAR(190) NOT NULL,
            version VARCHAR(32) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $database->exec(
        "INSERT INTO schema_migrations (migration, version, checksum, applied_at)
         VALUES
            ('extension:sample_plugin:20260805_001_sample', '1.0.0', REPEAT('a', 64), NOW()),
            ('extension:sampleXplugin:20260805_001_unrelated', '1.0.0', REPEAT('b', 64), NOW())"
    );

    $setInstalled = static function (): void {
        Core::setSetting('extensions.installed_versions', ['sample_plugin' => '1.0.0'], 'json', 'extensions');
        Core::setSetting('extensions.states', ['sample_plugin' => false], 'json', 'extensions');
    };
    $expect = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };

    $setInstalled();
    $result = Store::uninstall('sample_plugin', 'keep');
    $backupPaths[] = base_path((string) $result['backup']);
    $expect($result['data_removed'] === false, 'Keep mode reported removed data.');
    $expect(!is_dir($extensionRoot), 'Uninstall retained the active extension directory.');
    $expect(is_dir(end($backupPaths)), 'Uninstall did not retain a private file backup.');
    $expect((int) val("SELECT COUNT(*) FROM schema_migrations WHERE migration = 'extension:sample_plugin:20260805_001_sample'") === 1, 'Keep mode removed migration history.');
    $expect(!isset(Lifecycle::installedVersions()['sample_plugin']), 'Uninstall retained the installed version.');

    $makeFixture();
    $setInstalled();
    $result = Store::uninstall('sample_plugin', 'purge');
    $backupPaths[] = base_path((string) $result['backup']);
    $expect($result['data_removed'] === true, 'Purge mode did not report removed data.');
    $expect((int) val("SELECT COUNT(*) FROM schema_migrations WHERE migration = 'extension:sample_plugin:20260805_001_sample'") === 0, 'Purge mode retained migration history.');
    $expect((int) val("SELECT COUNT(*) FROM schema_migrations WHERE migration = 'extension:sampleXplugin:20260805_001_unrelated'") === 1, 'Purge mode removed another extension migration.');
    $expect(!isset(Lifecycle::installedVersions()['sample_plugin']), 'Purge mode retained the installed version.');

    echo "PASS extension uninstall lifecycle\n";
} finally {
    $removeTree($extensionRoot);
    foreach ($backupPaths as $backupPath) {
        $removeTree($backupPath);
    }
    if ($created && isset($server)) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
