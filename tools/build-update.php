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

$root = dirname(__DIR__);
$options = getopt('', ['version:', 'minimum-version::', 'minimum-php::', 'output::', 'key::', 'allow-dirty', 'without-migrations']);
$version = trim((string) ($options['version'] ?? ''));
$minimumVersion = trim((string) ($options['minimum-version'] ?? '1.0.4'));
$minimumPhp = trim((string) ($options['minimum-php'] ?? '8.4.0'));
$output = trim((string) ($options['output'] ?? ($root . DIRECTORY_SEPARATOR . 'dist')));
$keyPath = trim((string) ($options['key'] ?? ($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'update-signing.key')));
$allowDirty = array_key_exists('allow-dirty', $options);
$withoutMigrations = array_key_exists('without-migrations', $options);

$validVersion = static fn (string $value): bool => preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $value) === 1;

if (!$validVersion($version) || !$validVersion($minimumVersion) || !$validVersion($minimumPhp)) {
    fwrite(STDERR, "Usage: php tools/build-update.php --version=1.0.7 [--minimum-version=1.0.4] [--output=dist] [--without-migrations]\n");
    exit(1);
}

$core = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'Core.php');

if (preg_match("/public const string VERSION = '([^']+)'/", $core, $match) !== 1 || ($match[1] ?? '') !== $version) {
    fwrite(STDERR, "Core::VERSION must match the package version.\n");
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
    fwrite(STDERR, "A valid Ed25519 signing key is required. Run php tools/update-key.php first.\n");
    exit(1);
}

$allowedRoots = ['App', 'Public', 'assets', 'docs', 'lang', 'migrations'];
$rootFiles = ['index.php', 'cron.php', '.htaccess', 'LICENSE', 'README.md'];
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

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $relative) !== 1) {
            fwrite(STDERR, "Unsupported package path: {$relative}\n");
            exit(1);
        }

        $files[$relative] = $entry->getPathname();
    }
}

ksort($files, SORT_STRING);
$hashes = [];

foreach ($files as $relative => $path) {
    $hash = hash_file('sha256', $path);

    if (!is_string($hash)) {
        fwrite(STDERR, "Unable to hash {$relative}.\n");
        exit(1);
    }

    $hashes[$relative] = $hash;
}

$deletionsFile = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'update-deletions.json';
$deletions = [];

if (is_file($deletionsFile)) {
    try {
        $allDeletions = json_decode((string) file_get_contents($deletionsFile), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fwrite(STDERR, "Invalid tools/update-deletions.json: {$exception->getMessage()}\n");
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

if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(1);
}

$packageName = 'tinycat-' . $version . '.zip';
$packagePath = $output . DIRECTORY_SEPARATOR . $packageName;
@unlink($packagePath);

if (class_exists('ZipArchive')) {
    $archive = new ZipArchive();

    if ($archive->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "Unable to create the update archive.\n");
        exit(1);
    }

    foreach ($files as $relative => $path) {
        if (!$archive->addFile($path, $relative)) {
            fwrite(STDERR, "Unable to add {$relative} to the update archive.\n");
            exit(1);
        }
    }

    $archive->close();
} else {
    try {
        $archive = new PharData($packagePath, 0, null, Phar::ZIP);

        foreach ($files as $relative => $path) {
            $archive->addFile($path, $relative);
        }

        unset($archive);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Unable to create the update archive: {$exception->getMessage()}\n");
        exit(1);
    }
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
$manifestPath = $output . DIRECTORY_SEPARATOR . 'tinycat-update.json';
$signaturePath = $output . DIRECTORY_SEPARATOR . 'tinycat-update.sig';
file_put_contents($manifestPath, $manifestJson, LOCK_EX);
file_put_contents($signaturePath, base64_encode($signature) . "\n", LOCK_EX);
sodium_memzero($secretKey);

fwrite(STDOUT, "Created:\n");
fwrite(STDOUT, "  {$packagePath}\n  {$manifestPath}\n  {$signaturePath}\n");
