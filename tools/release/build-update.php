<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

if (!function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "The Sodium extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/App/Core/Support/Version.php';
$options = getopt('', ['version:', 'minimum-version::', 'minimum-php::', 'output::', 'key::', 'allow-dirty', 'without-migrations', 'fresh-install', 'both', 'assembly::', 'product::', 'active::', 'legacy-upgrade']);
$version = trim((string) ($options['version'] ?? ''));
$minimumVersion = trim((string) ($options['minimum-version'] ?? \TinyCat\Support\Version::MINIMUM_UPDATE));
$minimumPhp = trim((string) ($options['minimum-php'] ?? '8.4.0'));
$output = trim((string) ($options['output'] ?? ($root . DIRECTORY_SEPARATOR . 'dist')));
$keyPath = trim((string) ($options['key'] ?? ($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'update-signing.key')));
$allowDirty = array_key_exists('allow-dirty', $options);
$freshInstall = array_key_exists('fresh-install', $options);
$both = array_key_exists('both', $options);
$legacyUpgrade = array_key_exists('legacy-upgrade', $options);
if ($legacyUpgrade && ($freshInstall || array_key_exists('without-migrations', $options) || array_key_exists('minimum-version', $options))) { throw new InvalidArgumentException('Legacy upgrade chooses its supported minimum and requires migration execution.'); }
if ($both && $freshInstall) { throw new InvalidArgumentException('Use --both or --fresh-install, not both.'); }
$withoutMigrations = $freshInstall || array_key_exists('without-migrations', $options);

$validVersion = static fn (string $value): bool => preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $value) === 1;

if (!$validVersion($version) || !$validVersion($minimumVersion) || !$validVersion($minimumPhp)) {
    fwrite(STDERR, "Usage: php tools/release/build-update.php --version=X.Y.Z [--minimum-version=X.Y.Z] [--output=dist] [--without-migrations] [--fresh-install | --both]\n");
    exit(1);
}

if (version_compare($minimumVersion, \TinyCat\Support\Version::MINIMUM_UPDATE, '<') || version_compare($minimumVersion, $version, '>')) {
    fwrite(STDERR, 'Minimum update version must be between ' . \TinyCat\Support\Version::MINIMUM_UPDATE . ' and the package version.' . "\n");
    exit(1);
}

$core = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Version.php');

if (preg_match("/public const string CURRENT = '([^']+)'/", $core, $match) !== 1 || ($match[1] ?? '') !== $version) {
    fwrite(STDERR, "Version::CURRENT must match the package version.\n");
    exit(1);
}

if (!$allowDirty) {
    exec('git -C ' . escapeshellarg($root) . ' status --porcelain', $status, $exitCode);

    if ($exitCode !== 0 || $status !== []) {
        fwrite(STDERR, "The tracked worktree must be clean. Use --allow-dirty only for local testing.\n");
        exit(1);
    }
}

if (!str_starts_with($output, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $output) !== 1) {
    $output = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $output);
}

if (!str_starts_with($keyPath, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $keyPath) !== 1) {
    $keyPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $keyPath);
}

$secretEncoded = trim((string) getenv('TINYCAT_UPDATE_SIGNING_KEY'));

if ($secretEncoded === '') {
    $secretEncoded = is_file($keyPath) ? trim((string) file_get_contents($keyPath)) : '';
}

$secretKey = base64_decode($secretEncoded, true);

if (!is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "A valid Ed25519 signing key is required. Run php tools/release/update-key.php first.\n");
    exit(1);
}

$allowedRoots = ['App', 'Public', 'assets', 'docs', 'lang', 'migrations'];
if (!defined('TINYCAT')) { define('TINYCAT', true); }
require_once $root . '/App/autoload.php';
$assembly = (string) ($options['assembly'] ?? 'source');
$selection = match ($assembly) {
    'core' => [],
    'source' => require $root . '/App/modules.php',
    'platform' => require $root . '/App/available-modules.php',
    'blog' => require $root . '/App/Modules/Blog/selection.php',
    default => throw new InvalidArgumentException('Unknown assembly; use source, platform, blog or core.'),
};
$product = (string) ($options['product'] ?? match ($assembly) { 'core' => 'tinycat.core', 'blog' => 'tinycat.blog', default => 'tinycat.social' });
$active = isset($options['active']) ? ((string) $options['active'] === '' ? [] : explode(',', (string) $options['active'])) : null;
$distribution = \TinyCat\Core\Modules\Distribution::describe($root, $product, $selection, $active);
if (\TinyCat\Core\Modules\Distribution::modular($distribution)) {
    $moduleBaseline = $distribution['format'] === 3 ? \TinyCat\Core\Modules\Distribution::DOMAIN_RUNTIME : ($distribution['format'] === 2 ? \TinyCat\Core\Modules\Distribution::FRAMEWORK_RUNTIME : (isset($distribution['schemas']['tinycat.support']) ? \TinyCat\Core\Modules\Distribution::CORE_SCHEMA_RUNTIME : \TinyCat\Core\Modules\Distribution::RUNTIME));
    if (!array_key_exists('minimum-version', $options)) { $minimumVersion = $moduleBaseline; }
    if (version_compare($minimumVersion, $moduleBaseline, '<') || version_compare($version, $minimumVersion, '<')) { throw new InvalidArgumentException('Modular packages require the module-aware runtime baseline.'); }
}
\TinyCat\Core\Modules\Resources::configure(\TinyCat\Core\Modules\Loader::fromSelection($root, \TinyCat\Core\Modules\Distribution::selection($distribution, $distribution['active']))->catalog);
$rootFiles = ['index.php', 'scheduled-tasks.php', '.htaccess', 'LICENSE', 'README.md'];
$includedDocumentation = ['docs/reference/api.md', 'docs/operations/updates.md', 'docs/operations/module-deployment.md'];
if ($freshInstall) {
    $allowedRoots = array_values(array_diff($allowedRoots, ['migrations']));
}
$files = [];

foreach ($rootFiles as $file) {
    $path = $root . DIRECTORY_SEPARATOR . $file;

    if (is_file($path) && \TinyCat\Core\Modules\Distribution::includes($file, $distribution)) {
        $files[$file] = $path;
    }
}

foreach ($allowedRoots as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;

    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        if (!\TinyCat\Core\Modules\Distribution::includes($relative, $distribution)) { continue; }
        if (str_starts_with($relative, 'assets/build/') || str_starts_with($relative, 'App/Core/Support/compiled/')) { continue; }

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $relative) !== 1) {
            fwrite(STDERR, "Unsupported package path: {$relative}\n");
            exit(1);
        }

        if (str_starts_with($relative, 'docs/') && !in_array($relative, $includedDocumentation, true)) {
            continue;
        }

        $files[$relative] = $entry->getPathname();
    }
}

ksort($files, SORT_STRING);
if (!defined('TINYCAT')) { define('TINYCAT', true); }
require_once $root . '/App/autoload.php';
$hashes = [];
$normalizedContents = [];
$moduleMigrationFiles = [];
foreach ($distribution['schemas'] as $schema) {
    foreach ([...$schema['migrations'], ...(isset($schema['fresh']) ? [$schema['fresh']] : []), ...(isset($schema['legacy']) ? [$schema['legacy']] : [])] as $migration) { $moduleMigrationFiles[$migration['file']] = true; }
}

foreach ($files as $relative => $path) {
    $content = file_get_contents($path);
    if (!is_string($content)) { throw new RuntimeException('Unable to read ' . $relative); }
    if ((str_starts_with($relative, 'migrations/') && str_ends_with($relative, '.php')) || isset($moduleMigrationFiles[$relative])) {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
    }
    $normalizedContents[$relative] = $content;
    $hashes[$relative] = hash('sha256', $content);
}
$descriptor = json_encode($distribution, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$files['App/distribution.json'] = '';
$normalizedContents['App/distribution.json'] = $descriptor;
$hashes['App/distribution.json'] = hash('sha256', $descriptor);
$selectionSource = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($selection, true) . ";\n";
$normalizedContents['App/modules.php'] = $selectionSource;
$hashes['App/modules.php'] = hash('sha256', $selectionSource);

$compileAssets = require __DIR__ . '/../assets/compile-assets.php';
foreach ($compileAssets() as $relative => $content) {
    $files[$relative] = '';
    $normalizedContents[$relative] = $content;
    $hashes[$relative] = hash('sha256', $content);
}
ksort($files, SORT_STRING);
ksort($hashes, SORT_STRING);

$baseFiles = $files; $baseContents = $normalizedContents; $baseHashes = $hashes;
foreach ($both ? [false, true] : [$freshInstall] as $freshInstall) {
    $files = $baseFiles; $normalizedContents = $baseContents; $hashes = $baseHashes;
    $variantDistribution = $distribution; $variantMinimum = $minimumVersion; $compatibilityMigration = null;
    if ($legacyUpgrade && !$freshInstall) {
        $owners = \TinyCat\Core\Modules\Loader::fromSelection($root, $selection)->providers(\TinyCat\Core\Modules\ProvidesLegacyUpgrade::class);
        if ($owners === []) { throw new RuntimeException('This product has no declared legacy upgrade owner.'); }
        $targetPath = 'App/distribution-target.json';
        $targetSource = $normalizedContents['App/distribution.json'];
        $files[$targetPath] = ''; $normalizedContents[$targetPath] = $targetSource; $hashes[$targetPath] = hash('sha256', $targetSource);
        $variantDistribution = $distribution;
        $variantDistribution['format'] = 1; $variantDistribution['legacy'] = true; $variantDistribution['schemas'] = [];
        unset($variantDistribution['framework'], $variantDistribution['schemaOwners']);
        $variantDistribution = \TinyCat\Core\Modules\Distribution::validate($variantDistribution);
        $transportSource = json_encode($variantDistribution, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $normalizedContents['App/distribution.json'] = $transportSource; $hashes['App/distribution.json'] = hash('sha256', $transportSource);
        $history = [];
        foreach (glob($root . '/migrations/*.php') ?: [] as $legacyFile) {
            $relative = 'migrations/' . basename($legacyFile);
            $content = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($legacyFile));
            $files[$relative] = ''; $normalizedContents[$relative] = $content; $hashes[$relative] = hash('sha256', $content); $history[$relative] = $hashes[$relative];
        }
        ksort($history, SORT_STRING);
        $compatibilityMigration = 'migrations/20261114_900_legacy_to_' . strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', $version)) . '.php';
        $callback = "<?php\ndeclare(strict_types=1);\nif (!defined('TINYCAT')) { return null; }\nreturn static function (\\PDO \$pdo): void {\n    require dirname(__DIR__) . '/App/autoload.php';\n    \\TinyCat\\Core\\Updates\\LegacyUpdate::apply(\$pdo, dirname(__DIR__), " . var_export($version, true) . ', ' . var_export($hashes[$targetPath], true) . ', ' . var_export($history, true) . ", \\TinyCat\\Support\\Version::CURRENT);\n};\n";
        $files[$compatibilityMigration] = ''; $normalizedContents[$compatibilityMigration] = $callback; $hashes[$compatibilityMigration] = hash('sha256', $callback);
        $variantMinimum = \TinyCat\Support\Version::MINIMUM_UPDATE;
    }
    // A running pre-6 updater still resolves Cache through its already-loaded autoloader.
    // Ship only a loader at the former path; the implementation stays in Core.
    if (!$freshInstall && ($distribution['legacy'] || $legacyUpgrade) && version_compare($variantMinimum, '6.0.0-dev.1', '<')) {
        $bridgePath = 'App/Support/Cache.php';
        $bridge = "<?php\ndeclare(strict_types=1);\nrequire_once dirname(__DIR__) . '/autoload.php';\nrequire_once dirname(__DIR__) . '/Core/Support/Cache.php';\n";
        $files[$bridgePath] = '';
        $normalizedContents[$bridgePath] = $bridge;
        $hashes[$bridgePath] = hash('sha256', $bridge);
        ksort($files, SORT_STRING); ksort($hashes, SORT_STRING);
    }
    $withoutMigrations = $freshInstall || array_key_exists('without-migrations', $options);
    if ($freshInstall) {
        foreach (array_keys($files) as $relative) {
            if (str_starts_with($relative, 'migrations/')) { unset($files[$relative], $normalizedContents[$relative], $hashes[$relative]); }
        }
    }
    $variantOutput = $both ? $output . ($freshInstall ? '/install' : '/update') : $output;
    $deletionsFile = __DIR__ . '/update-deletions.json';
    $deletions = [];

    if (!$freshInstall && ($distribution['legacy'] || $distribution['format'] === 3) && is_file($deletionsFile)) {
        try {
            $allDeletions = json_decode((string) file_get_contents($deletionsFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, "Invalid tools/release/update-deletions.json: {$exception->getMessage()}\n");
            exit(1);
        }

        foreach ((array) $allDeletions as $deletionVersion => $paths) {
            if (version_compare((string) $deletionVersion, $variantMinimum, '>') && version_compare((string) $deletionVersion, $version, '<=')) {
                foreach ((array) $paths as $path) {
                    $deletions[] = (string) $path;
                }
            }
        }
    }

    $deletions = array_values(array_unique($deletions));
    if ($legacyUpgrade && !$freshInstall) { $deletions = array_values(array_filter($deletions, static fn (string $path): bool => $path !== 'App/Support/Cache.php')); }
    sort($deletions, SORT_STRING);
    $migrations = ($withoutMigrations || !$distribution['legacy'])
        ? []
        : array_values(array_filter(
            array_keys($files),
            static fn (string $path): bool => str_starts_with($path, 'migrations/') && str_ends_with($path, '.php')
        ));
    if ($compatibilityMigration !== null) { $migrations = [$compatibilityMigration]; }
    ksort($files, SORT_STRING); ksort($hashes, SORT_STRING);

    if (!is_dir($variantOutput) && !mkdir($variantOutput, 0775, true) && !is_dir($variantOutput)) {
        fwrite(STDERR, "Unable to create output directory.\n");
        exit(1);
    }

    $productStem = \TinyCat\Core\Modules\Distribution::stem($product, $distribution);
    $packageName = $productStem . '-' . $version . ($freshInstall ? '-install' : '') . '.zip';
    $workingOutput = $variantOutput . '/.build-' . bin2hex(random_bytes(8));
    if (!mkdir($workingOutput, 0700)) { throw new RuntimeException('Unable to create artifact work directory.'); }
    register_shutdown_function(static function () use ($workingOutput): void {
        foreach (glob($workingOutput . '/*') ?: [] as $file) {
            if (str_ends_with($file, '.previous')) { continue; }
            if (is_file($file) && !is_link($file)) { @unlink($file); }
        }
        @rmdir($workingOutput);
    });
    $packagePath = $workingOutput . DIRECTORY_SEPARATOR . $packageName;
    $sourceDateEpoch = trim((string) getenv('SOURCE_DATE_EPOCH'));

    if ($sourceDateEpoch === '') {
        $sourceDateEpoch = '315532800';
    }

    if (preg_match('/^[0-9]+$/', $sourceDateEpoch) !== 1) {
        fwrite(STDERR, "SOURCE_DATE_EPOCH must be a non-negative Unix timestamp.\n");
        exit(1);
    }

    $archiveTimestamp = max(315532800, (int) $sourceDateEpoch);
    $normalizeZipTimestamps = require __DIR__ . '/zip-timestamps.php';

    @unlink($packagePath);

    if (class_exists('ZipArchive')) {
        $archive = new ZipArchive();

        if ($archive->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fwrite(STDERR, "Unable to create the update archive.\n");
            exit(1);
        }

        foreach ($files as $relative => $path) {
            $content = $normalizedContents[$relative] ?? file_get_contents($path);

            if (!is_string($content)) {
                fwrite(STDERR, "Unable to read {$relative} for the update archive.\n");
                exit(1);
            }

            $added = $archive->addFromString($relative, $content);

            if (!$added) {
                fwrite(STDERR, "Unable to add {$relative} to the update archive.\n");
                exit(1);
            }

            if (method_exists($archive, 'setMtimeName') && !$archive->setMtimeName($relative, $archiveTimestamp)) {
                fwrite(STDERR, "Unable to normalize the archive timestamp for {$relative}.\n");
                exit(1);
            }
        }

        if (!$archive->close()) { throw new RuntimeException('Unable to finalize the update archive.'); }
    } else {
        try {
            $archive = new PharData($packagePath, 0, null, Phar::ZIP);

            foreach ($files as $relative => $path) {
                $content = $normalizedContents[$relative] ?? file_get_contents($path);

                if (!is_string($content)) {
                    throw new RuntimeException("Unable to read {$relative} for the update archive.");
                }

                $archive->addFromString($relative, $content);
            }

            unset($archive);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Unable to create the update archive: {$exception->getMessage()}\n");
            exit(1);
        }
    }

    try {
        $normalizeZipTimestamps($packagePath, $archiveTimestamp);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Unable to normalize the update archive: {$exception->getMessage()}\n");
        exit(1);
    }

    $packageHash = hash_file('sha256', $packagePath);
    $packageSize = filesize($packagePath);

    if (!is_string($packageHash) || !is_int($packageSize) || $packageSize < 1) {
        fwrite(STDERR, "Unable to hash the update archive.\n");
        exit(1);
    }

    $manifest = [
        'kind' => $freshInstall ? 'install' : 'update',
        'distribution' => $variantDistribution,
        'version' => $version,
        'minimum_version' => $freshInstall && version_compare($variantMinimum, \TinyCat\Core\Modules\Distribution::RUNTIME, '<') ? \TinyCat\Core\Modules\Distribution::RUNTIME : $variantMinimum,
        'minimum_php' => $minimumPhp,
        'package' => $packageName,
        'sha256' => $packageHash,
        'size' => $packageSize,
        'files' => $hashes,
        'delete' => $deletions,
        'migrations' => $migrations,
    ];
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $signature = sodium_crypto_sign_detached($manifestJson, $secretKey);
    $manifestName = $productStem . ($freshInstall ? '-install' : '-update');
    $manifestPath = $workingOutput . DIRECTORY_SEPARATOR . $manifestName . '.json';
    $signaturePath = $workingOutput . DIRECTORY_SEPARATOR . $manifestName . '.sig';
    if (file_put_contents($manifestPath, $manifestJson, LOCK_EX) !== strlen($manifestJson)) { throw new RuntimeException('Unable to write manifest.'); }
    $encodedSignature = base64_encode($signature) . "\n";
    if (file_put_contents($signaturePath, $encodedSignature, LOCK_EX) !== strlen($encodedSignature)) { throw new RuntimeException('Unable to write signature.'); }

    $publish = require __DIR__ . '/publish-artifact.php';
    $publish($variantOutput, [$packageName => $packagePath, $manifestName . '.json' => $manifestPath, $manifestName . '.sig' => $signaturePath]);
    @rmdir($workingOutput);
    $packagePath = $variantOutput . DIRECTORY_SEPARATOR . $packageName;
    $manifestPath = $variantOutput . DIRECTORY_SEPARATOR . $manifestName . '.json';
    $signaturePath = $variantOutput . DIRECTORY_SEPARATOR . $manifestName . '.sig';

    fwrite(STDOUT, "Created:\n");
    fwrite(STDOUT, "  {$packagePath}\n  {$manifestPath}\n  {$signaturePath}\n");
}
sodium_memzero($secretKey);
