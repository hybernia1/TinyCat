<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

use \TinyCat\Updates\Manager;

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

$passed = 0;
$failed = 0;
$skipped = 0;

$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL {$name}: {$exception->getMessage()}\n";
    }
};

$expect = static function (bool $condition, string $message = 'Expectation failed.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$invoke = static function (string $method, mixed ...$arguments): mixed {
    $reflection = new ReflectionMethod(Manager::class, $method);
    return $reflection->invoke(null, ...$arguments);
};

$expectFailure = static function (callable $callback): void {
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException('Expected operation was accepted.');
};

$test('shared package manager validates remote package inputs', static function () use ($invoke, $expect, $expectFailure): void {
    $expect($invoke('httpsUrl', ' https://github.com/hybernia1/TinyCat ') === 'https://github.com/hybernia1/TinyCat');
    $expect($invoke('httpsUrl', 'https://user@example.test/package.zip') === '');
    $expect($invoke('validVersion', '2.0.14'));
    $expect(!$invoke('validVersion', '2.0'));
    $expect($invoke('decodeJson', '{"package":"tinycat.zip"}', 'package') === ['package' => 'tinycat.zip']);
    $expectFailure(static fn (): string => $invoke('githubUrl', 'https://example.test/package.zip', 'Invalid package host.'));
});

$test('managed update paths accept runtime files', static function () use ($invoke, $expect): void {
    $expect($invoke('managedPath', 'App/PackageManager.php') === 'App/PackageManager.php');
    $expect($invoke('managedPath', 'App/Bots.php') === 'App/Bots.php');
    $expect($invoke('managedPath', 'docs/updates.md') === 'docs/updates.md');
    $expect($invoke('managedPath', 'scheduled-tasks.php') === 'scheduled-tasks.php');
});

$test('maintenance recovery uses canonical API routes', static function () use ($invoke, $expect): void {
    foreach (['/api/updates/checks', '/api/updates/installations', '/api/updates/maintenance', '/api/users/sessions', '/api/users/sessions/current', '/api/installation/steps/db'] as $path) {
        $expect($invoke('maintenancePathAllowed', $path), 'Required recovery route blocked: ' . $path);
    }
    foreach (['/api/auth/login', '/api/auth/logout', '/api/admin/updates', '/api/statuses', '/api/scheduler/executions'] as $path) {
        $expect(!$invoke('maintenancePathAllowed', $path), 'Noncanonical or normal writes allowed during maintenance: ' . $path);
    }
});

$test('managed update paths reject protected and traversal targets', static function () use ($invoke, $expectFailure): void {
    foreach (['Extensions/Bots/extension.json', 'config.php', 'storage/cache.php', 'uploads/avatar.php', '../index.php', 'App//Core.php', '/App/Support/Version.php'] as $path) {
        $expectFailure(static fn (): mixed => $invoke('managedPath', $path));
    }
});

$test('manifest rejects case collisions and write-delete overlap', static function () use ($invoke, $expectFailure): void {
    $base = [
        'version' => '1.0.5',
        'minimum_version' => '1.0.4',
        'minimum_php' => '8.4.0',
        'package' => 'tinycat-1.0.5.zip',
        'sha256' => str_repeat('a', 64),
        'size' => 1024,
        'files' => ['App/Support/Version.php' => str_repeat('b', 64)],
        'delete' => [],
        'migrations' => [],
    ];
    $collision = $base;
    $collision['files']['App/Support/version.php'] = str_repeat('c', 64);
    $expectFailure(static fn (): mixed => $invoke('validateManifest', $collision));
    $overlap = $base;
    $overlap['delete'] = ['App/Support/Version.php'];
    $expectFailure(static fn (): mixed => $invoke('validateManifest', $overlap));

    $fileDirectoryCollision = $base;
    $fileDirectoryCollision['files']['App/Support/Version.php/bootstrap.php'] = str_repeat('d', 64);
    $expectFailure(static fn (): mixed => $invoke('validateManifest', $fileDirectoryCollision));

    $deleteCollision = $base;
    $deleteCollision['delete'] = ['Public/old.php', 'public/OLD.php'];
    $expectFailure(static fn (): mixed => $invoke('validateManifest', $deleteCollision));
});

$test('file backups exclude unchanged files from a complete release package', static function () use ($invoke, $expect): void {
    $source = \TinyCat\Support\Paths::basePath('App/Support/Version.php');
    $sourceHash = hash_file('sha256', $source);

    if (!is_string($sourceHash)) {
        throw new RuntimeException('Unable to hash updater test fixture.');
    }

    $backup = $invoke('createBackup', [
        'files' => ['App/Support/Version.php' => $sourceHash],
        'delete' => [],
        'migrations' => [],
    ], '9.9.9-test', false);

    try {
        $metadata = json_decode((string) file_get_contents($backup . DIRECTORY_SEPARATOR . 'backup.json'), true);
        $expect(is_array($metadata));
        $expect(($metadata['files'] ?? null) === []);
        $expect(($metadata['database_backup_required'] ?? true) === false);
        $expect(!is_file($backup . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'Version.php'));
    } finally {
        $invoke('removeDirectory', $backup);
    }
});

$test('maintenance state is explicit and reversible', static function () use ($invoke, $expect): void {
    Manager::disableMaintenance();
    $invoke('enableMaintenance', '9.9.9');

    try {
        $expect(Manager::maintenanceActive());
        $expect((Manager::maintenanceState()['to_version'] ?? '') === '9.9.9');
    } finally {
        Manager::disableMaintenance();
    }

    $expect(!Manager::maintenanceActive());
});

$test('Phar ZIP package extraction verifies every file hash', static function () use ($invoke, $expect): void {
    $root = \TinyCat\Support\Paths::basePath('storage/updater-test-' . bin2hex(random_bytes(5)));
    $package = $root . DIRECTORY_SEPARATOR . 'package.zip';
    $stage = $root . DIRECTORY_SEPARATOR . 'stage';
    mkdir($root, 0775, true);

    try {
        $archive = new PharData($package, 0, null, Phar::ZIP);
        $content = "<?php\necho 'verified';\n";
        $archive->addFromString('App/Test.php', $content);
        unset($archive);
        $manifest = [
            'files' => ['App/Test.php' => hash('sha256', $content)],
        ];
        $invoke('extractPackage', $package, $stage, $manifest);
        $expect(file_get_contents($stage . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'Test.php') === $content);
    } finally {
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($root);
        }
    }
});

$keyFile = \TinyCat\Support\Paths::basePath('storage/update-signing.key');

if (is_file($keyFile)) {
    $test('release signature accepts authentic data and rejects tampering', static function () use ($invoke, $expectFailure, $keyFile): void {
        $secret = base64_decode(trim((string) file_get_contents($keyFile)), true);

        if (!is_string($secret)) {
            throw new RuntimeException('Invalid local signing key.');
        }

        $manifest = "{\"version\":\"1.0.5\"}\n";
        $signature = base64_encode(sodium_crypto_sign_detached($manifest, $secret));
        $invoke('verifyManifestSignature', $manifest, $signature);
        $expectFailure(static fn (): mixed => $invoke('verifyManifestSignature', $manifest . ' ', $signature));
        sodium_memzero($secret);
    });
} else {
    $skipped++;
    echo "SKIP release signature test: local signing key is unavailable.\n";
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $test('database backup is required only for pending migrations', static function () use ($expect): void {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
        $checksum = str_repeat('a', 64);

        $expect(!\TinyCat\Updates\MigrationRegistry::hasPending([]));
        $expect(\TinyCat\Updates\MigrationRegistry::hasPending(['test_001' => $checksum]));

        \TinyCat\Updates\MigrationRegistry::ensure();
        \TinyCat\Database\Connection::insert('schema_migrations', [
            'migration' => 'test_001',
            'version' => '1.0.5',
            'checksum' => $checksum,
            'applied_at' => \TinyCat\Support\Dates::dateDb(),
        ]);
        $expect(!\TinyCat\Updates\MigrationRegistry::hasPending(['test_001' => $checksum]));
        $expect(\TinyCat\Updates\MigrationRegistry::hasPending(['test_001' => str_repeat('b', 64)]));
    });

    $test('migration registry stores version and checksum', static function () use ($expect): void {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
        \TinyCat\Updates\MigrationRegistry::ensure();
        \TinyCat\Database\Connection::insert('schema_migrations', [
            'migration' => 'test_001',
            'version' => '1.0.5',
            'checksum' => str_repeat('a', 64),
            'applied_at' => \TinyCat\Support\Dates::dateDb(),
        ]);
        $expect((int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM schema_migrations') === 1);
    });

    $test('outdated migration registry is rejected by the major baseline', static function () use ($expectFailure): void {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
        \TinyCat\Database\Connection::exec(
            'CREATE TABLE schema_migrations (
                version VARCHAR(80) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            )'
        );
        \TinyCat\Database\Connection::insert('schema_migrations', ['version' => '20260731_legacy']);
        $expectFailure(static fn (): mixed => \TinyCat\Updates\MigrationRegistry::ensure());
    });

    $test('malformed migration registry is not mistaken for the current schema', static function () use ($expectFailure): void {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
        \TinyCat\Database\Connection::exec(
            'CREATE TABLE schema_migrations (
                migration VARCHAR(190) NOT NULL,
                version VARCHAR(32) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (version)
            )'
        );
        $expectFailure(static fn (): mixed => \TinyCat\Updates\MigrationRegistry::ensure());
    });
} else {
    $skipped++;
    echo "SKIP migration registry test: pdo_sqlite is unavailable.\n";
}

echo "\nUpdater tests: {$passed} passed, {$failed} failed, {$skipped} skipped.\n";
exit($failed === 0 ? 0 : 1);
