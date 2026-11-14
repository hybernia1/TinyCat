<?php
declare(strict_types=1);

// Exercises the authenticated shared-storage bridge and the supplied domain-owned artifact.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/App/Core/Support/Version.php';
$version = \TinyCat\Support\Version::CURRENT;
$baselineVersion = '6.0.0-dev.9.2';
$options = getopt('', ['artifact::', 'key::', 'legacy-baseline::', 'bridge-bootstrap::', 'bridge-checkpoint::']);
$legacyBaseline = $options['legacy-baseline'] ?? null;
if ($legacyBaseline !== null && !in_array($legacyBaseline, ['5.16.0', '5.22.2'], true)) {
    throw new InvalidArgumentException('Use the supported legacy release baseline.');
}
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

    if ($legacyBaseline !== null) {
        $baselineVersion = $legacyBaseline;
        $baselineArchive = $temporaryRoot . '/legacy-source.zip';
        exec('git -C ' . escapeshellarg($root) . ' archive --format=zip --output=' . escapeshellarg($baselineArchive) . ' ' . escapeshellarg('v' . $baselineVersion), $archiveOutput, $archiveExit);
        if ($archiveExit !== 0) { throw new RuntimeException('Cannot extract the published legacy baseline.'); }
    } else {
        $baselineArchive = $root . '/tests/fixtures/releases/bridge-6.0.0-dev.9.1-source.zip';
        if (hash_file('sha256', $baselineArchive) !== '9620cebe0f1fb03d6a49673ebc3daef43a54e53800ffdaedc1bdb660cfeb3df5') { throw new RuntimeException('The frozen compatibility source changed.'); }
    }
    $extractZip($baselineArchive, $installRoot);
    if ($legacyBaseline === null) {
        $versionPath = $installRoot . '/App/Core/Support/Version.php';
        file_put_contents($versionPath, str_replace("CURRENT = '6.0.0-dev.9.1'", "CURRENT = '6.0.0-dev.9.2'", (string) file_get_contents($versionPath)), LOCK_EX);
    }
    $installConfig = $baseConfig;
    $installConfig['database'] = [...$databaseConfig, 'name' => $databaseName];
    $installConfig['app'] = [...(array) ($baseConfig['app'] ?? []), 'url' => 'http://localhost'];
    $installConfig['install'] = ['complete' => true, 'locale' => 'en'];
    $installConfig['cache'] = [...(array) ($baseConfig['cache'] ?? []), 'driver' => 'filesystem', 'directory' => $installRoot . '/storage/cache'];
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
if (class_exists(TinyCat\Core\Modules\Distribution::class)) {
    $plan = TinyCat\Core\Modules\Distribution::describe(__ROOT__, 'tinycat.social', require __ROOT__ . '/App/modules.php');
    file_put_contents(__ROOT__ . '/App/distribution.json', json_encode($plan, JSON_THROW_ON_ERROR));
}
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

    (require $root . '/tests/fixtures/releases/seed-shared-social.php')($database);
    $assert(true, 'Representative shared-storage users, posts, comments, reactions, tags, links and notifications are seeded.');

    if ($legacyBaseline !== null) {
        $legacyCounts = $databaseFingerprint($database)['counts'];
        foreach (['bridge-bootstrap', 'bridge-checkpoint'] as $phase) {
            $directory = realpath((string) ($options[$phase] ?? ''));
            if ($directory === false || !is_file($directory . '/tinycat-update.json')) { throw new RuntimeException('Provide both signed bridge directories for a legacy rehearsal.'); }
            $bridgeManifest = json_decode((string) file_get_contents($directory . '/tinycat-update.json'), true, 512, JSON_THROW_ON_ERROR);
            $bridgeRunner = <<<'PHP'
define('TINYCAT', true);
require __ROOT__ . '/App/bootstrap.php';
if (method_exists(TinyCat\Updates\Manager::class, 'installLocalPackage')) {
    TinyCat\Updates\Manager::installLocalPackage(__MANIFEST__, __SIGNATURE__, __PACKAGE__);
} else {
    // Exercise the published 5.x updater's verified file-only bridge sequence.
    $invoke = static fn (string $method, mixed ...$arguments): mixed => (new ReflectionMethod(TinyCat\Updates\Manager::class, $method))->invoke(null, ...$arguments);
    $manifest = TinyCat\Updates\Manager::verifyLocalPackage(__MANIFEST__, __SIGNATURE__, __PACKAGE__);
    if ($manifest['migrations'] !== []) { throw new RuntimeException('The initial legacy bridge must be file-only.'); }
    $invoke('assertCompatibility', $manifest);
    $stage = __ROOT__ . '/storage/updates/legacy-bridge-stage';
    $invoke('extractPackage', __PACKAGE__, $stage, $manifest);
    $invoke('preflightManagedTargets', $manifest);
    $invoke('createBackup', $manifest, $manifest['version'], false);
    $invoke('enableMaintenance', $manifest['version']);
    $invoke('applyFiles', $stage, $manifest);
    $invoke('deleteLegacyFiles', $manifest);
    $invoke('clearRuntimeCache');
    TinyCat\Updates\Manager::disableMaintenance();
}
PHP;
            [$bridgeExit, $bridgeOutput] = $run(str_replace(
                ['__ROOT__', '__MANIFEST__', '__SIGNATURE__', '__PACKAGE__'],
                [var_export($installRoot, true), var_export($directory . '/tinycat-update.json', true), var_export($directory . '/tinycat-update.sig', true), var_export($directory . '/' . basename($bridgeManifest['package']), true)],
                $bridgeRunner
            ));
            if ($bridgeExit !== 0) { throw new RuntimeException('Legacy ' . $phase . ' failed: ' . implode(' ', $bridgeOutput)); }
            $baselineVersion = $bridgeManifest['version'];
            $assert(true, 'The signed ' . $phase . ' completes against the actual legacy database.');
        }
        foreach ($legacyCounts as $table => $count) {
            if (!in_array($table, ['settings', 'schema_migrations'], true)) {
                $assert((int) $database->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === $count, 'Both bridge steps preserve legacy rows in ' . $table);
            }
        }
        $assert(true, 'Legacy migrations complete before domain-owned storage conversion.');
    }

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
$result = TinyCat\Core\Updates\Manager::installLocalPackage(__MANIFEST__, __SIGNATURE__, __PACKAGE__);
$backup = __ROOT__ . '/' . $result['backup'];
$metadata = json_decode((string) file_get_contents($backup . '/backup.json'), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(['backup' => $backup, 'pending_migrations' => isset($metadata['database_identity']), 'applied_migrations' => $result['migrations']], JSON_THROW_ON_ERROR);
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
    $assert(($update['pending_migrations'] ?? null) === ($expectedPending !== [] || ($manifest['distribution']['schemas'] ?? []) !== []), 'Pending migrations match the baseline registry.');
    $assert(($update['applied_migrations'] ?? null) === $expectedPending, 'Only migrations absent from the baseline run.');
    foreach (['users'=>'users','content'=>'social_posts','content_comments'=>'social_comments','content_likes'=>'social_post_likes','comment_likes'=>'social_comment_likes','user_followers'=>'user_followers','notifications'=>'notifications','content_tags'=>'social_post_tags','terms'=>'social_terms','content_links'=>'social_post_links'] as $old => $table) {
        $assert((int) $database->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === $beforeDatabase['counts'][$old], 'Update preserves representative records in ' . $table);
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

    $rollbackRunner = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; TinyCat\\Core\\Updates\\Manager::rollback(" . var_export(basename($backup), true) . ");";
    [$rollbackExit, $rollbackOutput] = $run($rollbackRunner);
    $assert($rollbackExit === 0, 'Standard updater restores its recorded database and file snapshot: ' . implode(' ', $rollbackOutput));
    $assert($inventory($installRoot) === $beforeInventory, 'Rollback restores the exact managed bridge tree.');
    $assert($databaseFingerprint($database) === $beforeDatabase, 'Rollback restores the exact pre-update database content and schema.');
    $clearOldCache = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; (new ReflectionMethod(TinyCat\\Updates\\Manager::class, 'clearRuntimeCache'))->invoke(null);";
    [$clearCacheExit, $clearCacheOutput] = $run($clearOldCache);
    $assert($clearCacheExit === 0, 'Rollback invalidates settings cached by the updated runtime: ' . implode(' ', $clearCacheOutput));
    [$oldBootExit, $oldBootOutput] = $run($probeRunner);
    $assert($oldBootExit === 0, 'Rolled-back compatibility runtime boots: ' . implode(' ', $oldBootOutput));

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

echo "PASS {$baselineVersion} representative MySQL update, data preservation and rollback ({$checks} checks)\n";
