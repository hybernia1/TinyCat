<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/functions.php';

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
    $reflection = new ReflectionMethod(Updater::class, $method);
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

$test('managed update paths accept runtime files', static function () use ($invoke, $expect): void {
    $expect($invoke('managedPath', 'App/Updater.php') === 'App/Updater.php');
    $expect($invoke('managedPath', 'docs/updates.md') === 'docs/updates.md');
    $expect($invoke('managedPath', 'scheduled-tasks.php') === 'scheduled-tasks.php');
});

$test('managed update paths reject protected and traversal targets', static function () use ($invoke, $expectFailure): void {
    foreach (['config.php', 'storage/cache.php', 'uploads/avatar.php', '../index.php', 'App//Core.php', '/App/Core.php'] as $path) {
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
        'files' => ['App/Core.php' => str_repeat('b', 64)],
        'delete' => [],
        'migrations' => [],
    ];
    $collision = $base;
    $collision['files']['App/core.php'] = str_repeat('c', 64);
    $expectFailure(static fn (): mixed => $invoke('validateManifest', $collision));
    $overlap = $base;
    $overlap['delete'] = ['App/Core.php'];
    $expectFailure(static fn (): mixed => $invoke('validateManifest', $overlap));
});

$test('maintenance state is explicit and reversible', static function () use ($invoke, $expect): void {
    Updater::disableMaintenance();
    $invoke('enableMaintenance', '9.9.9');

    try {
        $expect(Updater::maintenanceActive());
        $expect((Updater::maintenanceState()['to_version'] ?? '') === '9.9.9');
    } finally {
        Updater::disableMaintenance();
    }

    $expect(!Updater::maintenanceActive());
});

$test('Phar ZIP package extraction verifies every file hash', static function () use ($invoke, $expect): void {
    $root = base_path('storage/updater-test-' . bin2hex(random_bytes(5)));
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

$keyFile = base_path('storage/update-signing.key');

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
    $test('migration registry stores version and checksum', static function () use ($invoke, $expect): void {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Core::setDb($database);
        $invoke('ensureMigrationTable');
        insert('schema_migrations', [
            'migration' => 'test_001',
            'version' => '1.0.5',
            'checksum' => str_repeat('a', 64),
            'applied_at' => date_db(),
        ]);
        $expect((int) val('SELECT COUNT(*) FROM schema_migrations') === 1);
    });

    $test('legacy migration registry is upgraded without losing history', static function () use ($invoke, $expect): void {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Core::setDb($database);
        run(
            'CREATE TABLE schema_migrations (
                version VARCHAR(80) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            )'
        );
        insert('schema_migrations', ['version' => '20260731_legacy']);

        $invoke('ensureMigrationTable');
        $invoke('ensureMigrationTable');
        $legacy = one('SELECT migration, version, checksum FROM schema_migrations LIMIT 1');
        $expect(($legacy['migration'] ?? '') === '20260731_legacy', 'Legacy migration identifier was not preserved.');
        $expect(($legacy['version'] ?? '') === '20260731_legacy', 'Legacy version history was not preserved.');
        $expect(preg_match('/^[a-f0-9]{64}$/', (string) ($legacy['checksum'] ?? '')) === 1, 'Legacy checksum was not backfilled.');

        insert('schema_migrations', [
            'migration' => '20260805_new',
            'version' => '1.0.7',
            'checksum' => str_repeat('b', 64),
            'applied_at' => date_db(),
        ]);
        $expect((int) val('SELECT COUNT(*) FROM schema_migrations') === 2);
    });
} else {
    $skipped++;
    echo "SKIP migration registry test: pdo_sqlite is unavailable.\n";
}

echo "\nUpdater tests: {$passed} passed, {$failed} failed, {$skipped} skipped.\n";
exit($failed === 0 ? 0 : 1);
