<?php
declare(strict_types=1);

define('TINYCAT', true);

$root = dirname(__DIR__, 2);
require_once $root . '/App/Core.php';

function db(): PDO
{
    return Core::db();
}

function run(string $sql, array $params = []): int
{
    return Core::exec($sql, $params);
}

function all(string $sql, array $params = []): array
{
    return Core::all($sql, $params);
}

function one(string $sql, array $params = []): ?array
{
    return Core::one($sql, $params);
}

function insert(string $table, array $data): string
{
    return Core::insert($table, $data);
}

function date_db(mixed $value = null): string
{
    return Core::dateDb($value);
}

function db_transaction(callable $callback): mixed
{
    $database = db();
    $database->beginTransaction();

    try {
        $result = $callback();
        $database->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

require_once $root . '/App/MigrationRegistry.php';
require_once $root . '/App/UserRoles.php';
require_once $root . '/App/ExtensionRegistry.php';
require_once $root . '/App/ExtensionLoader.php';
require_once $root . '/App/ExtensionLifecycle.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP extension lifecycle: pdo_sqlite is unavailable.\n";
    exit(0);
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Core::setDb($database);
run(
    'CREATE TABLE settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key VARCHAR(120) NOT NULL UNIQUE,
        setting_group VARCHAR(60) NOT NULL DEFAULT \'general\',
        setting_value TEXT NULL,
        setting_type VARCHAR(20) NOT NULL DEFAULT \'string\',
        autoload INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

insert('settings', [
    'setting_key' => 'extensions.installed_versions',
    'setting_group' => 'extensions',
    'setting_value' => json_encode(['sample-existing' => '2.0.0'], JSON_THROW_ON_ERROR),
    'setting_type' => 'json',
    'autoload' => 1,
]);
$adoptBots = require $root . '/migrations/20260805_003_bots_extension_adoption.php';
$adoptBots($database);
$adoptBots($database);
$adoptedVersions = Core::setting('extensions.installed_versions', []);

if (
    ($adoptedVersions['bots'] ?? '') !== '1.0.0'
    || ($adoptedVersions['sample-existing'] ?? '') !== '2.0.0'
    || (int) Core::value("SELECT COUNT(*) FROM settings WHERE setting_key = 'extensions.installed_versions'") !== 1
) {
    throw new RuntimeException('Legacy Bots extension adoption is not restart-safe.');
}

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tinycat-extension-lifecycle-' . bin2hex(random_bytes(8));
$sampleRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'Sample';
$migrationDirectory = $sampleRoot . DIRECTORY_SEPARATOR . 'migrations';

try {
    mkdir($migrationDirectory, 0777, true);
    file_put_contents($sampleRoot . DIRECTORY_SEPARATOR . 'entry.php', "<?php\n");
    $migrationPath = $migrationDirectory . DIRECTORY_SEPARATOR . '20260805_001_create_sample.php';
    file_put_contents($migrationPath, <<<'PHP'
<?php
declare(strict_types=1);

return static function (PDO $database): void {
    $database->exec('CREATE TABLE IF NOT EXISTS sample_data (id INTEGER PRIMARY KEY)');
};
PHP);
    file_put_contents($sampleRoot . DIRECTORY_SEPARATOR . 'extension.json', json_encode([
        'schema' => 1,
        'slug' => 'sample',
        'name' => 'Sample',
        'version' => '1.2.0',
        'requires' => ['tinycat' => '1.0.0'],
        'entry' => 'entry.php',
        'migrations' => ['migrations/20260805_001_create_sample.php'],
        'autoload' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    ExtensionLoader::boot($temporaryRoot, ['sample' => false]);
    $before = ExtensionLifecycle::all()['sample'] ?? [];
    if (!empty($before['installed']) || (int) ($before['pending_migrations'] ?? 0) !== 1) {
        throw new RuntimeException('Uninstalled extension status was not detected.');
    }

    $result = ExtensionLifecycle::migrate('sample');
    $migrationId = 'extension:sample:20260805_001_create_sample';
    if (
        ($result['version'] ?? '') !== '1.2.0'
        || ($result['migrations'] ?? []) !== [$migrationId]
        || (int) Core::value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sample_data'") !== 1
    ) {
        throw new RuntimeException('Extension migration was not applied.');
    }

    $restarted = ExtensionLifecycle::migrate('sample');
    $after = ExtensionLifecycle::all()['sample'] ?? [];
    if (
        ($restarted['migrations'] ?? []) !== []
        || ($after['installed_version'] ?? '') !== '1.2.0'
        || (int) ($after['pending_migrations'] ?? -1) !== 0
        || count(MigrationRegistry::history('extension:sample:')) !== 1
    ) {
        throw new RuntimeException('Extension migration is not restart-safe.');
    }

    file_put_contents($migrationPath, "<?php return static function (PDO \$database): void {};\n");
    $tampered = ExtensionLifecycle::all()['sample'] ?? [];
    if (trim((string) ($tampered['migration_error'] ?? '')) === '') {
        throw new RuntimeException('Applied extension migration tampering was not detected.');
    }

    echo "PASS extension lifecycle: version stored, migration namespaced, restart-safe, checksum enforced.\n";
} finally {
    if (is_dir($temporaryRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($temporaryRoot);
    }
}
