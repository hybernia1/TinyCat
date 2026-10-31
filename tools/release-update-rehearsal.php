<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/App/Support/Version.php';
$version = \TinyCat\Support\Version::CURRENT;
$baselineVersion = \TinyCat\Support\Version::MINIMUM_UPDATE;
$options = getopt('', ['artifact::', 'key::']);
$artifactRoot = trim((string) ($options['artifact'] ?? ($root . '/dist/release-' . $version)));
$configPath = $root . '/config.php';
$temporaryRoot = $root . '/storage/release-update-' . bin2hex(random_bytes(6));
$installRoot = $temporaryRoot . '/install';
$baselineArchive = $temporaryRoot . '/tinycat-' . $baselineVersion . '.zip';
$databaseName = 'tinycat_release_update_' . bin2hex(random_bytes(6));
$server = null;
$database = null;
$created = false;
$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};
$removeTree = static function (string $path) use (&$removeTree, $temporaryRoot): void {
    $temporary = realpath($temporaryRoot);
    $resolved = realpath($path);

    if (!is_string($temporary) || !is_string($resolved) || !str_starts_with($resolved, $temporary)) {
        return;
    }

    foreach (scandir($resolved) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $resolved . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) && !is_link($child) ? $removeTree($child) : unlink($child);
    }

    rmdir($resolved);
};
$extractZip = static function (string $archive, string $target): void {
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create release update extraction directory.');
    }

    $zip = new PharData($archive);
    $zip->extractTo($target, null, true);
    unset($zip);
};
$run = static function (string $script) use ($temporaryRoot): array {
    $runner = $temporaryRoot . '/runner-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($runner, "<?php\ndeclare(strict_types=1);\n" . $script, LOCK_EX);
    $output = [];

    try {
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg($runner) . ' 2>&1', $output, $exitCode);
        return [$exitCode, $output];
    } finally {
        if (is_file($runner)) {
            unlink($runner);
        }
    }
};
$inventory = static function (string $base): array {
    $roots = ['App', 'Extensions', 'Public', 'assets', 'docs', 'lang', 'migrations'];
    $rootFiles = ['index.php', 'scheduled-tasks.php', '.htaccess', 'LICENSE', 'README.md'];
    $files = [];

    foreach ($rootFiles as $relative) {
        $path = $base . '/' . $relative;

        if (is_file($path)) {
            $files[$relative] = hash_file('sha256', $path);
        }
    }

    foreach ($roots as $rootName) {
        $directory = $base . '/' . $rootName;

        if (!is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
                $files[$relative] = hash_file('sha256', $entry->getPathname());
            }
        }
    }

    ksort($files, SORT_STRING);
    return $files;
};
$databaseFingerprint = static function (PDO $pdo): array {
    $tables = [
        'users', 'content', 'content_comments', 'content_likes', 'comment_likes', 'user_followers',
        'notifications', 'content_tags', 'terms', 'content_links', 'settings', 'schema_migrations',
    ];
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    $samples = [];

    foreach ([
        'SELECT id, username, role, status FROM users ORDER BY id',
        'SELECT id, author_id, body, published_at FROM content ORDER BY id',
        'SELECT id, content_id, user_id, parent_id, body FROM content_comments ORDER BY id',
        'SELECT setting_key, setting_value, setting_type FROM settings ORDER BY setting_key',
        'SELECT migration, version, checksum FROM schema_migrations ORDER BY migration',
    ] as $query) {
        $samples[] = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }

    $schema = $pdo->query(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, ORDINAL_POSITION'
    )->fetchAll(PDO::FETCH_ASSOC);

    return [
        'counts' => $counts,
        'content_sha256' => hash('sha256', json_encode($samples, JSON_THROW_ON_ERROR)),
        'schema_sha256' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
    ];
};

try {
    if (!is_file($configPath)) {
        throw new RuntimeException('Local MySQL configuration is required for the product update rehearsal.');
    }

    $baseConfig = require $configPath;
    $databaseConfig = is_array($baseConfig['database'] ?? null) ? $baseConfig['database'] : [];
    $host = trim((string) ($databaseConfig['host'] ?? ''));
    $port = (int) ($databaseConfig['port'] ?? 3306);
    $charset = trim((string) ($databaseConfig['charset'] ?? 'utf8mb4'));

    if ($host === '' || !array_key_exists('user', $databaseConfig) || preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
        throw new RuntimeException('Local MySQL configuration is incomplete.');
    }
    if (preg_match('/^tinycat_release_update_[a-f0-9]{12}$/', $databaseName) !== 1) {
        throw new RuntimeException('Unsafe rehearsal database name.');
    }
    if (!is_dir($artifactRoot)) {
        throw new RuntimeException('Release artifact directory is missing.');
    }

    $manifestPath = $artifactRoot . '/tinycat-update.json';
    $signaturePath = $artifactRoot . '/tinycat-update.sig';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $packagePath = $artifactRoot . '/' . basename((string) ($manifest['package'] ?? ''));
    $keyEncoded = trim((string) file_get_contents((string) ($options['key'] ?? ($root . '/storage/update-signing.key'))));
    $secretKey = base64_decode($keyEncoded, true);

    if (!is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('Local release signing key is unavailable.');
    }

    $publicKey = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secretKey));
    sodium_memzero($secretKey);
    $server = new PDO(
        "mysql:host={$host};port={$port};charset={$charset}",
        (string) $databaseConfig['user'],
        (string) ($databaseConfig['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $server->exec("CREATE DATABASE `{$databaseName}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci");
    $created = true;
    $database = new PDO(
        "mysql:host={$host};port={$port};dbname={$databaseName};charset={$charset}",
        (string) $databaseConfig['user'],
        (string) ($databaseConfig['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );

    if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
        throw new RuntimeException('Unable to create product rehearsal directory.');
    }

    exec(
        'git -C ' . escapeshellarg($root) . ' archive --format=zip --output=' . escapeshellarg($baselineArchive) . ' ' . escapeshellarg('v' . $baselineVersion),
        $archiveOutput,
        $archiveExit
    );

    if ($archiveExit !== 0) {
        throw new RuntimeException('Unable to archive supported baseline v' . $baselineVersion . ': ' . implode(' ', $archiveOutput));
    }

    $extractZip($baselineArchive, $installRoot);
    $installConfig = $baseConfig;
    $installConfig['database'] = [...$databaseConfig, 'name' => $databaseName];
    $installConfig['app'] = [...(array) ($baseConfig['app'] ?? []), 'url' => 'http://localhost'];
    $installConfig['install'] = ['complete' => true, 'locale' => 'en'];
    $installConfig['cache'] = [...(array) ($baseConfig['cache'] ?? []), 'driver' => 'filesystem'];
    $installConfig['updates'] = [...(array) ($baseConfig['updates'] ?? []), 'public_key' => $publicKey];
    file_put_contents($installRoot . '/config.php', "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($installConfig, true) . ";\n", LOCK_EX);

    foreach (['storage', 'uploads/avatars'] as $directory) {
        $path = $installRoot . '/' . $directory;

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    file_put_contents($installRoot . '/storage/release-sentinel.txt', 'storage-' . $baselineVersion, LOCK_EX);
    file_put_contents($installRoot . '/uploads/avatars/release-sentinel.txt', 'uploads-' . $baselineVersion, LOCK_EX);
    $installRunner = <<<'PHP'
define('TINYCAT', true);
require __ROOT__ . '/App/bootstrap.php';
TinyCat\Installation\Installer::create_tables();
TinyCat\Installation\Installer::create_admin_account('release_admin', 'Release-Rehearsal-2026', 'en');
TinyCat\Installation\Installer::default_settings(['locale' => 'en']);
// The archived installer creates its final schema directly. Model a production
// baseline whose historical migrations have already completed.
foreach (glob(__ROOT__ . '/migrations/*.php') ?: [] as $path) {
    TinyCat\Updates\MigrationRegistry::ensure();
    TinyCat\Database\Connection::exec(
        'INSERT INTO schema_migrations (migration, version, checksum, applied_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)',
        [pathinfo($path, PATHINFO_FILENAME), TinyCat\Support\Version::CURRENT, TinyCat\Updates\MigrationRegistry::checksum($path)]
    );
}
PHP;
    [$installExit, $installOutput] = $run(str_replace('__ROOT__', var_export($installRoot, true), $installRunner));
    if ($installExit !== 0) {
        throw new RuntimeException('Baseline installer failed: ' . implode(' ', $installOutput));
    }
    $assert(true, 'Supported baseline installer prepares the representative database.');

    require_once $root . '/tools/demo-import/Schema.php';
    $seedSchema = new \TinyCat\Tools\DemoImport\Schema($database);
    $seedSchema->validate(false);
    $seedSchema->validate(true);

    $importCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/demo-import.php')
        . ' --config=' . escapeshellarg($installRoot . '/config.php')
        . ' --profile=small --users=60 --min-posts=3 --max-posts=5'
        . ' --min-comments=8 --max-comments=14 --min-comment-likes=1 --max-comment-likes=3'
        . ' --batch-size=100 --seed=926 --prefix=release --reset=1 --resume=0 --jsonl=1';
    exec($importCommand . ' 2>&1', $importOutput, $importExit);
    $assert($importExit === 0, 'Batched deterministic representative import completes: ' . implode(' ', array_slice($importOutput, -3)));

    $beforeDatabase = $databaseFingerprint($database);
    $appliedBefore = $database->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $expectedPending = array_values(array_filter(
        array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), (array) ($manifest['migrations'] ?? [])),
        static fn (string $id): bool => !in_array($id, $appliedBefore, true)
    ));
    $beforeInventory = $inventory($installRoot);
    $protectedHashes = [
        'config' => hash_file('sha256', $installRoot . '/config.php'),
        'storage' => hash_file('sha256', $installRoot . '/storage/release-sentinel.txt'),
        'uploads' => hash_file('sha256', $installRoot . '/uploads/avatars/release-sentinel.txt'),
    ];
    $probeRunner = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; if (TinyCat\\Support\\Version::CURRENT !== " . var_export($baselineVersion, true) . ") exit(2);";
    [$probeExit, $probeOutput] = $run($probeRunner);
    $assert($probeExit === 0, 'Supported baseline boots before update: ' . implode(' ', $probeOutput));

    $updateRunner = <<<'PHP'
define('TINYCAT', true);
require __ROOT__ . '/App/bootstrap.php';
$invoke = static function (string $method, mixed ...$arguments): mixed {
    return (new ReflectionMethod(TinyCat\Updates\Manager::class, $method))->invoke(null, ...$arguments);
};
$manifest = TinyCat\Updates\Manager::verifyLocalPackage(__MANIFEST__, __SIGNATURE__, __PACKAGE__);
$invoke('assertCompatibility', $manifest);
$pending = $invoke('hasPendingMigrations', $manifest);
$stage = __ROOT__ . '/storage/updates/release-stage';
$invoke('extractPackage', __PACKAGE__, $stage, $manifest);
$invoke('preflightManagedTargets', $manifest);
$backup = $invoke('createBackup', $manifest, (string) $manifest['version'], $pending);
if ($pending) {
    $invoke('backupDatabase', $backup);
}
$invoke('enableMaintenance', (string) $manifest['version']);
$invoke('applyFiles', $stage, $manifest);
$applied = $invoke('applyMigrations', $stage, $manifest);
$invoke('deleteLegacyFiles', $manifest);
$invoke('clearRuntimeCache');
TinyCat\Updates\Manager::disableMaintenance();
echo json_encode([
    'backup' => $backup,
    'pending_migrations' => $pending,
    'applied_migrations' => $applied,
], JSON_THROW_ON_ERROR);
PHP;
    $updateRunner = str_replace(
        ['__ROOT__', '__MANIFEST__', '__SIGNATURE__', '__PACKAGE__'],
        [var_export($installRoot, true), var_export($manifestPath, true), var_export($signaturePath, true), var_export($packagePath, true)],
        $updateRunner
    );
    [$updateExit, $updateOutput] = $run($updateRunner);
    $update = json_decode(implode("\n", $updateOutput), true);
    if ($updateExit !== 0 || !is_array($update)) {
        throw new RuntimeException('Signed production update failed: ' . implode(' ', $updateOutput));
    }
    $assert(($update['pending_migrations'] ?? null) === ($expectedPending !== []), 'Pending migrations match the baseline registry.');
    $assert(($update['applied_migrations'] ?? null) === $expectedPending, 'Only migrations absent from the baseline run.');
    foreach (['users', 'content', 'content_comments', 'content_likes', 'comment_likes', 'user_followers', 'notifications', 'content_tags', 'terms', 'content_links'] as $table) {
        $assert((int) $database->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === $beforeDatabase['counts'][$table], 'Update preserves representative records in ' . $table);
    }
    foreach ((array) ($manifest['migrations'] ?? []) as $path) {
        $id = pathinfo((string) $path, PATHINFO_FILENAME);
        $statement = $database->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
        $statement->execute([$id]);
        $assert($statement->fetchColumn() === ($manifest['files'][$path] ?? null), 'Updated database records the signed migration: ' . $id);
    }

    foreach ($protectedHashes as $label => $hash) {
        $path = match ($label) {
            'config' => $installRoot . '/config.php',
            'storage' => $installRoot . '/storage/release-sentinel.txt',
            'uploads' => $installRoot . '/uploads/avatars/release-sentinel.txt',
            default => throw new RuntimeException('Unknown protected path.'),
        };
        $assert(hash_file('sha256', $path) === $hash, ucfirst($label) . ' survives update byte-for-byte.');
    }

    $newBootRunner = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; if (TinyCat\\Support\\Version::CURRENT !== " . var_export($version, true) . " || !class_exists('TinyCat\\\\Bots\\\\Importer') || class_exists('TinyCat\\\\Extension\\\\Loader')) exit(2);";
    [$newBootExit, $newBootOutput] = $run($newBootRunner);
    $assert($newBootExit === 0, 'Updated runtime boots with built-in Bots: ' . implode(' ', $newBootOutput));

    $backup = is_array($update) ? (string) ($update['backup'] ?? '') : '';
    $metadata = json_decode((string) @file_get_contents($backup . '/backup.json'), true);
    $assert(is_array($metadata) && ($metadata['from_version'] ?? null) === $baselineVersion, 'Rollback backup records exact source version.');
    $assert(($metadata['database_backup_required'] ?? null) === (bool) ($update['pending_migrations'] ?? false), 'Database backup requirement matches pending migrations.');
    $needsDatabaseRestore = (bool) ($update['pending_migrations'] ?? false);
    $assert(!$needsDatabaseRestore || is_file($backup . '/database.sql'), 'Schema-changing updates include a MySQL snapshot.');

    foreach ((array) ($metadata['files'] ?? []) as $relative => $hash) {
        $source = $backup . '/files/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);
        $target = $installRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to restore release backup file: ' . $relative);
        }
    }

    foreach (array_keys((array) ($manifest['files'] ?? [])) as $relative) {
        if (array_key_exists($relative, $beforeInventory)) {
            continue;
        }

        $path = $installRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);

        if (is_file($path)) {
            unlink($path);
        }
    }

    $assert($inventory($installRoot) === $beforeInventory, 'Rollback restores the exact managed 5.16.0 tree.');

    // A rollback of a release with migrations must restore both files and data.
    if (preg_match('/^tinycat_release_update_[a-f0-9]{12}$/', $databaseName) !== 1
        || (string) $database->query('SELECT DATABASE()')->fetchColumn() !== $databaseName
    ) {
        throw new RuntimeException('Refusing to restore outside the disposable rehearsal database.');
    }
    if ($needsDatabaseRestore) {
        $databaseBackup = file_get_contents($backup . '/database.sql');
        if (!is_string($databaseBackup) || $databaseBackup === '') {
            throw new RuntimeException('The rollback database snapshot is unreadable.');
        }
        $database->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $tables = $database->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $table) !== 1) {
                    throw new RuntimeException('Unsafe table name in the disposable rollback database.');
                }
                $database->exec('DROP TABLE `' . $table . '`');
            }
            $database->exec($databaseBackup);
        } finally {
            $database->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    $assert($databaseFingerprint($database) === $beforeDatabase, 'Rollback restores the exact pre-update database content and schema.');
    $clearOldCache = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; (new ReflectionMethod(TinyCat\\Updates\\Manager::class, 'clearRuntimeCache'))->invoke(null);";
    [$clearCacheExit, $clearCacheOutput] = $run($clearOldCache);
    $assert($clearCacheExit === 0, 'Rollback invalidates settings cached by the updated runtime: ' . implode(' ', $clearCacheOutput));
    [$oldBootExit, $oldBootOutput] = $run($probeRunner);
    $assert($oldBootExit === 0, 'Rolled-back 5.16.0 runtime boots: ' . implode(' ', $oldBootOutput));

    echo json_encode([
        'version' => (string) ($manifest['version'] ?? ''),
        'minimum_version' => (string) ($manifest['minimum_version'] ?? ''),
        'package_sha256' => (string) ($manifest['sha256'] ?? ''),
        'package_size' => (int) ($manifest['size'] ?? 0),
        'files' => count((array) ($manifest['files'] ?? [])),
        'deletions' => count((array) ($manifest['delete'] ?? [])),
        'migrations' => count((array) ($manifest['migrations'] ?? [])),
        'database_counts' => $beforeDatabase['counts'],
        'checks' => $checks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
} finally {
    $database = null;

    if ($created && $server instanceof PDO && preg_match('/^tinycat_release_update_[a-f0-9]{12}$/', $databaseName) === 1) {
        try {
            $server->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
        } catch (Throwable $exception) {
            $failures[] = 'Unable to remove product rehearsal database: ' . $exception->getMessage();
        }
    }

    $server = null;

    if (is_dir($temporaryRoot)) {
        $removeTree($temporaryRoot);
    }
}

if ($failures !== []) {
    foreach (array_unique($failures) as $failure) {
        echo "FAIL {$failure}\n";
    }

    echo "\nRelease update rehearsal: {$checks} checks, " . count(array_unique($failures)) . " failures.\n";
    exit(1);
}

echo "PASS exact 5.16.0 representative MySQL update, data preservation and rollback ({$checks} checks)\n";
