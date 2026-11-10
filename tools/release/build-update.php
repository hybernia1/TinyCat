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
require_once $root . '/App/Support/Version.php';
$options = getopt('', ['version:', 'minimum-version::', 'minimum-php::', 'output::', 'key::', 'allow-dirty', 'without-migrations', 'fresh-install', 'both']);
$version = trim((string) ($options['version'] ?? ''));
$minimumVersion = trim((string) ($options['minimum-version'] ?? \TinyCat\Support\Version::MINIMUM_UPDATE));
$minimumPhp = trim((string) ($options['minimum-php'] ?? '8.4.0'));
$output = trim((string) ($options['output'] ?? ($root . DIRECTORY_SEPARATOR . 'dist')));
$keyPath = trim((string) ($options['key'] ?? ($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'update-signing.key')));
$allowDirty = array_key_exists('allow-dirty', $options);
$freshInstall = array_key_exists('fresh-install', $options);
$both = array_key_exists('both', $options);
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

$core = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Version.php');

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
$rootFiles = ['index.php', 'scheduled-tasks.php', '.htaccess', 'LICENSE', 'README.md'];
$includedDocumentation = ['docs/reference/api.md', 'docs/operations/updates.md'];
if ($freshInstall) {
    $allowedRoots = array_values(array_diff($allowedRoots, ['migrations']));
}
$files = [];

foreach ($rootFiles as $file) {
    $path = $root . DIRECTORY_SEPARATOR . $file;

    if (is_file($path)) {
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
        if (str_starts_with($relative, 'assets/build/') || str_starts_with($relative, 'App/Support/compiled/')) { continue; }

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
$hashes = [];
$normalizedContents = [];

foreach ($files as $relative => $path) {
    $content = file_get_contents($path);
    if (!is_string($content)) { throw new RuntimeException('Unable to read ' . $relative); }
    if (str_starts_with($relative, 'migrations/') && str_ends_with($relative, '.php')) {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
    }
    $normalizedContents[$relative] = $content;
    $hashes[$relative] = hash('sha256', $content);
}

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
    $withoutMigrations = $freshInstall || array_key_exists('without-migrations', $options);
    if ($freshInstall) {
        foreach (array_keys($files) as $relative) {
            if (str_starts_with($relative, 'migrations/')) { unset($files[$relative], $normalizedContents[$relative], $hashes[$relative]); }
        }
    }
    $variantOutput = $both ? $output . ($freshInstall ? '/install' : '/update') : $output;
    $deletionsFile = __DIR__ . '/update-deletions.json';
    $deletions = [];

    if (!$freshInstall && is_file($deletionsFile)) {
        try {
            $allDeletions = json_decode((string) file_get_contents($deletionsFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fwrite(STDERR, "Invalid tools/release/update-deletions.json: {$exception->getMessage()}\n");
            exit(1);
        }

        foreach ((array) $allDeletions as $deletionVersion => $paths) {
            if (version_compare((string) $deletionVersion, $minimumVersion, '>') && version_compare((string) $deletionVersion, $version, '<=')) {
                foreach ((array) $paths as $path) {
                    $deletions[] = (string) $path;
                }
            }
        }
    }

    $deletions = array_values(array_unique($deletions));
    sort($deletions, SORT_STRING);
    $migrations = $withoutMigrations
        ? []
        : array_values(array_filter(
            array_keys($files),
            static fn (string $path): bool => str_starts_with($path, 'migrations/') && str_ends_with($path, '.php')
        ));

    if (!is_dir($variantOutput) && !mkdir($variantOutput, 0775, true) && !is_dir($variantOutput)) {
        fwrite(STDERR, "Unable to create output directory.\n");
        exit(1);
    }

    $packageName = 'tinycat-' . $version . ($freshInstall ? '-install' : '') . '.zip';
    $workingOutput = $variantOutput . '/.build-' . bin2hex(random_bytes(8));
    if (!mkdir($workingOutput, 0700)) { throw new RuntimeException('Unable to create artifact work directory.'); }
    register_shutdown_function(static function () use ($workingOutput): void {
        foreach (glob($workingOutput . '/tinycat-*') ?: [] as $file) {
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
        'version' => $version,
        'minimum_version' => $minimumVersion,
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
    $manifestName = $freshInstall ? 'tinycat-install' : 'tinycat-update';
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
