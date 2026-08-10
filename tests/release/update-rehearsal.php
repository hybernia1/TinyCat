<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$temporaryRoot = $root . '/storage/update-rehearsal-' . bin2hex(random_bytes(6));
$candidateRoot = $temporaryRoot . '/candidate';
$baselineArchive = $temporaryRoot . '/tinycat-2.0.25.zip';
$installRoot = $temporaryRoot . '/install';
$freshRoot = $temporaryRoot . '/fresh';
$artifactRoot = $temporaryRoot . '/dist';
$signingKey = $temporaryRoot . '/signing.key';
$candidateVersion = '2.0.38';
$failures = [];
$managedRoots = ['App', 'Extensions', 'Public', 'assets', 'docs', 'lang', 'migrations'];
$managedRootFiles = ['index.php', 'scheduled-tasks.php', '.htaccess', 'LICENSE', 'README.md'];
$assert = static function (bool $condition, string $message) use (&$failures): void {
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
$copyTree = static function (string $source, string $target) use (&$copyTree): void {
    if (is_file($source)) {
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Unable to create candidate directory.');
        }

        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to copy candidate file: ' . $source);
        }

        return;
    }

    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create candidate directory.');
    }

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $source . DIRECTORY_SEPARATOR . $entry;

        if (is_link($child)) {
            continue;
        }

        $copyTree($child, $target . DIRECTORY_SEPARATOR . $entry);
    }
};
$extractZip = static function (string $archive, string $target): void {
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create rehearsal extraction directory.');
    }

    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Unable to open rehearsal archive.');
        }

        try {
            if (!$zip->extractTo($target)) {
                throw new RuntimeException('Unable to extract rehearsal archive.');
            }
        } finally {
            $zip->close();
        }

        return;
    }

    $phar = new PharData($archive);
    $phar->extractTo($target, null, true);
    unset($phar);
};
$inventory = static function (string $base) use ($managedRoots, $managedRootFiles): array {
    $files = [];

    foreach ($managedRootFiles as $relative) {
        $path = $base . DIRECTORY_SEPARATOR . $relative;

        if (is_file($path)) {
            $files[$relative] = hash_file('sha256', $path);
        }
    }

    foreach ($managedRoots as $rootName) {
        $directory = $base . DIRECTORY_SEPARATOR . $rootName;

        if (!is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
            $files[$relative] = hash_file('sha256', $entry->getPathname());
        }
    }

    ksort($files, SORT_STRING);
    return $files;
};
$run = static function (string $script) use ($temporaryRoot): array {
    $runner = $temporaryRoot . '/runner-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($runner, "<?php\ndeclare(strict_types=1);\n" . $script, LOCK_EX);
    $output = [];

    try {
        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg($runner);
        exec($command . ' 2>&1', $output, $exitCode);
        return [$exitCode, $output];
    } finally {
        if (is_file($runner)) {
            unlink($runner);
        }
    }
};
$configSource = static fn (string $publicKey): string => "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
    'app' => ['url' => 'http://localhost'],
    'install' => ['complete' => false, 'locale' => 'en'],
    'updates' => ['public_key' => $publicKey],
], true) . ";\n";

try {
    if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
        throw new RuntimeException('Unable to create update rehearsal directory.');
    }

    foreach ($managedRootFiles as $relative) {
        $copyTree($root . '/' . $relative, $candidateRoot . '/' . $relative);
    }

    foreach ($managedRoots as $relative) {
        $copyTree($root . '/' . $relative, $candidateRoot . '/' . $relative);
    }

    $copyTree($root . '/tools/build-update.php', $candidateRoot . '/tools/build-update.php');
    $copyTree($root . '/tools/update-deletions.json', $candidateRoot . '/tools/update-deletions.json');
    $core = (string) file_get_contents($candidateRoot . '/App/Core.php');

    if (!str_contains($core, "public const string VERSION = '{$candidateVersion}';")) {
        throw new RuntimeException('Candidate Core::VERSION does not match the release rehearsal.');
    }
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
    file_put_contents($signingKey, base64_encode($secretKey) . "\n", LOCK_EX);
    $build = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($candidateRoot . '/tools/build-update.php')
        . ' --version=' . escapeshellarg($candidateVersion)
        . ' --minimum-version=2.0.25 --allow-dirty --without-migrations'
        . ' --output=' . escapeshellarg($artifactRoot)
        . ' --key=' . escapeshellarg($signingKey);
    exec($build . ' 2>&1', $buildOutput, $buildExit);

    if ($buildExit !== 0) {
        throw new RuntimeException('Rehearsal package build failed: ' . implode(' ', $buildOutput));
    }

    $archiveCommand = 'git -C ' . escapeshellarg($root) . ' archive --format=zip'
        . ' --output=' . escapeshellarg($baselineArchive) . ' v2.0.25';
    exec($archiveCommand . ' 2>&1', $archiveOutput, $archiveExit);

    if ($archiveExit !== 0) {
        throw new RuntimeException('Unable to archive v2.0.25: ' . implode(' ', $archiveOutput));
    }

    $extractZip($baselineArchive, $installRoot);
    file_put_contents($installRoot . '/config.php', $configSource($publicKey), LOCK_EX);

    foreach (['storage', 'uploads'] as $runtimeDirectory) {
        $path = $installRoot . '/' . $runtimeDirectory;

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create rehearsal runtime directory: ' . $runtimeDirectory);
        }
    }

    $extensionRoot = $installRoot . '/Extensions/rehearsal_probe';
    mkdir($extensionRoot, 0775, true);
    file_put_contents($extensionRoot . '/bootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);

TinyCat\Extension\Registry::register('rehearsal_probe', ['root' => __DIR__]);
PHP);
    file_put_contents($extensionRoot . '/extension.json', json_encode([
        'schema' => 1,
        'slug' => 'rehearsal_probe',
        'name' => 'Release rehearsal probe',
        'version' => '1.0.0',
        'requires' => ['tinycat' => '2.0.25', 'php' => '8.4.0'],
        'entry' => 'bootstrap.php',
        'migrations' => [],
        'autoload' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    file_put_contents($installRoot . '/storage/rehearsal-sentinel.txt', 'storage-preserved');
    file_put_contents($installRoot . '/uploads/rehearsal-sentinel.txt', 'uploads-preserved');
    $configHash = hash_file('sha256', $installRoot . '/config.php');
    $extensionHash = hash_file('sha256', $extensionRoot . '/bootstrap.php');
    $baselineInventory = $inventory($installRoot);
    $manifestPath = $artifactRoot . '/tinycat-update.json';
    $signaturePath = $artifactRoot . '/tinycat-update.sig';
    $packagePath = $artifactRoot . '/tinycat-' . $candidateVersion . '.zip';
    $runner = <<<'PHP'
define('TINYCAT', true);
$root = __INSTALL_ROOT__;
require $root . '/App/bootstrap.php';
$invoke = static function (string $method, mixed ...$arguments): mixed {
    return (new ReflectionMethod(TinyCat\Update\Manager::class, $method))->invoke(null, ...$arguments);
};
$manifest = TinyCat\Update\Manager::verifyLocalPackage(__MANIFEST__, __SIGNATURE__, __PACKAGE__);
$invoke('assertCompatibility', $manifest);
$stage = $root . '/storage/updates/rehearsal-stage';
$invoke('extractPackage', __PACKAGE__, $stage, $manifest);
$invoke('preflightManagedTargets', $manifest);
$backup = $invoke('createBackup', $manifest, __VERSION__, false);
$invoke('enableMaintenance', __VERSION__);
$maintenance = TinyCat\Update\Manager::maintenanceActive();
$invoke('applyFiles', $stage, $manifest);
$invoke('deleteLegacyFiles', $manifest);
$invoke('clearRuntimeCache');
TinyCat\Update\Manager::disableMaintenance();
echo json_encode([
    'backup' => $backup,
    'maintenance_during' => $maintenance,
    'maintenance_after' => TinyCat\Update\Manager::maintenanceActive(),
    'files' => count($manifest['files']),
    'deletions' => count($manifest['delete']),
], JSON_THROW_ON_ERROR);
PHP;
    $runner = str_replace(
        ['__INSTALL_ROOT__', '__MANIFEST__', '__SIGNATURE__', '__PACKAGE__', '__VERSION__'],
        [
            var_export($installRoot, true),
            var_export($manifestPath, true),
            var_export($signaturePath, true),
            var_export($packagePath, true),
            var_export($candidateVersion, true),
        ],
        $runner
    );
    [$updateExit, $updateOutput] = $run($runner);
    $updateResult = json_decode(implode("\n", $updateOutput), true);
    $assert($updateExit === 0 && is_array($updateResult), 'Updater rehearsal completes: ' . implode(' ', $updateOutput));
    $assert(($updateResult['maintenance_during'] ?? null) === true, 'Maintenance mode is active while files change.');
    $assert(($updateResult['maintenance_after'] ?? null) === false, 'Maintenance mode is disabled after success.');
    $assert((int) ($updateResult['deletions'] ?? -1) === 3, 'Patch candidate removes obsolete profile link partials and the email template page.');
    $assert((string) file_get_contents($installRoot . '/storage/rehearsal-sentinel.txt') === 'storage-preserved', 'Storage survives update.');
    $assert((string) file_get_contents($installRoot . '/uploads/rehearsal-sentinel.txt') === 'uploads-preserved', 'Uploads survive update.');
    $assert(hash_file('sha256', $installRoot . '/config.php') === $configHash, 'Configuration survives update byte-for-byte.');
    $assert(hash_file('sha256', $extensionRoot . '/bootstrap.php') === $extensionHash, 'Compatible unmanaged extension survives update.');
    $bootNew = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; if (Core::VERSION !== " . var_export($candidateVersion, true) . ") exit(2);";
    [$newBootExit, $newBootOutput] = $run($bootNew);
    $assert($newBootExit === 0, 'Updated candidate runtime boots: ' . implode(' ', $newBootOutput));
    $extensionProbe = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; \$found=TinyCat\\Extension\\Loader::discover(" . var_export($installRoot . '/Extensions', true)
        . "); if (!(\$found['rehearsal_probe']['compatible'] ?? false)) exit(2);";
    [$extensionExit, $extensionOutput] = $run($extensionProbe);
    $assert($extensionExit === 0, 'Compatible 2.0.25 extension remains discoverable after update: ' . implode(' ', $extensionOutput));

    $updatedInventory = $inventory($installRoot);
    $repeatRunner = <<<'PHP'
define('TINYCAT', true);
$root = __INSTALL_ROOT__;
require $root . '/App/bootstrap.php';
$invoke = static function (string $method, mixed ...$arguments): mixed {
    return (new ReflectionMethod(TinyCat\Update\Manager::class, $method))->invoke(null, ...$arguments);
};
$manifest = TinyCat\Update\Manager::verifyLocalPackage(__MANIFEST__, __SIGNATURE__, __PACKAGE__);
$stage = $root . '/storage/updates/rehearsal-repeat-stage';
$invoke('extractPackage', __PACKAGE__, $stage, $manifest);
$backup = $invoke('createBackup', $manifest, __VERSION__, false);
$invoke('enableMaintenance', __VERSION__);
$invoke('applyFiles', $stage, $manifest);
$invoke('deleteLegacyFiles', $manifest);
TinyCat\Update\Manager::disableMaintenance();
echo json_encode(['backup' => $backup], JSON_THROW_ON_ERROR);
PHP;
    $repeatRunner = str_replace(
        ['__INSTALL_ROOT__', '__MANIFEST__', '__SIGNATURE__', '__PACKAGE__', '__VERSION__'],
        [
            var_export($installRoot, true),
            var_export($manifestPath, true),
            var_export($signaturePath, true),
            var_export($packagePath, true),
            var_export($candidateVersion, true),
        ],
        $repeatRunner
    );
    [$repeatExit, $repeatOutput] = $run($repeatRunner);
    $repeatResult = json_decode(implode("\n", $repeatOutput), true);
    $repeatBackup = is_array($repeatResult) ? (string) ($repeatResult['backup'] ?? '') : '';
    $repeatMetadata = json_decode((string) @file_get_contents($repeatBackup . '/backup.json'), true);
    $assert($repeatExit === 0 && is_array($repeatResult), 'Repeated signed update completes: ' . implode(' ', $repeatOutput));
    $assert($inventory($installRoot) === $updatedInventory, 'Repeated signed update leaves the candidate inventory unchanged.');
    $assert(is_array($repeatMetadata) && ($repeatMetadata['files'] ?? null) === [], 'Repeated signed update does not back up unchanged files.');

    $interruptRunner = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; (new ReflectionMethod(TinyCat\\Update\\Manager::class, 'enableMaintenance'))->invoke(null, "
        . var_export($candidateVersion, true) . "); exit(23);";
    [$interruptExit] = $run($interruptRunner);
    $assert($interruptExit === 23, 'Synthetic update interruption occurs after maintenance is persisted.');
    $recoverRunner = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; if (!TinyCat\\Update\\Manager::maintenanceActive()) exit(2); TinyCat\\Update\\Manager::disableMaintenance();";
    [$recoverExit, $recoverOutput] = $run($recoverRunner);
    $assert($recoverExit === 0, 'Interrupted update is detected and maintenance can be recovered: ' . implode(' ', $recoverOutput));

    $backup = is_array($updateResult) ? (string) ($updateResult['backup'] ?? '') : '';
    $metadata = json_decode((string) @file_get_contents($backup . '/backup.json'), true);
    $assert(is_array($metadata) && ($metadata['from_version'] ?? '') === '2.0.25', 'Rollback backup records source version 2.0.25.');

    foreach ((array) ($metadata['files'] ?? []) as $relative => $hash) {
        $source = $backup . '/files/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);
        $target = $installRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to restore backup file: ' . $relative);
        }
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

    foreach (array_keys((array) ($manifest['files'] ?? [])) as $relative) {
        if (array_key_exists($relative, $baselineInventory)) {
            continue;
        }

        $path = $installRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);

        if (is_file($path)) {
            unlink($path);
        }
    }

    $restoredInventory = $inventory($installRoot);
    $assert($restoredInventory === $baselineInventory, 'Rollback restores the exact v2.0.25 managed inventory.');
    $assert(hash_file('sha256', $installRoot . '/config.php') === $configHash, 'Rollback leaves configuration unchanged.');
    $assert(hash_file('sha256', $extensionRoot . '/bootstrap.php') === $extensionHash, 'Rollback leaves the compatible extension unchanged.');
    $bootOld = "define('TINYCAT', true); require " . var_export($installRoot . '/App/bootstrap.php', true)
        . "; if (Core::VERSION !== '2.0.25') exit(2);";
    [$oldBootExit, $oldBootOutput] = $run($bootOld);
    $assert($oldBootExit === 0, 'Restored v2.0.25 runtime boots: ' . implode(' ', $oldBootOutput));

    $extractZip($packagePath, $freshRoot);
    file_put_contents($freshRoot . '/config.php', $configSource($publicKey), LOCK_EX);
    $freshBoot = "define('TINYCAT', true); require " . var_export($freshRoot . '/App/bootstrap.php', true)
        . "; if (Core::VERSION !== " . var_export($candidateVersion, true) . ") exit(2);";
    [$freshExit, $freshOutput] = $run($freshBoot);
    $assert($freshExit === 0, 'Fresh candidate artifact boots: ' . implode(' ', $freshOutput));
    sodium_memzero($secretKey);
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
} finally {
    if (is_dir($temporaryRoot)) {
        $removeTree($temporaryRoot);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    echo "\nUpdate rehearsal: " . count($failures) . " failures.\n";
    exit(1);
}

echo "PASS signed 2.0.25 update, repeat/interruption recovery, exact rollback and fresh-artifact rehearsal\n";
