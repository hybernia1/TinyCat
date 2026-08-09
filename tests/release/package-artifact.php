<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/bootstrap.php';

$temporaryRoot = $root . '/storage/package-artifact-' . bin2hex(random_bytes(6));
$output = $temporaryRoot . '/dist';
$keyPath = $temporaryRoot . '/signing.key';
$failures = [];
$names = [];
$managedPath = static function (string $path): string {
    $path = str_replace('\\', '/', trim($path));

    if ($path === ''
        || str_starts_with($path, '/')
        || preg_match('~^[A-Za-z]:/~', $path) === 1
        || preg_match('~(?:^|/)\.\.?(/|$)~', $path) === 1
        || preg_match('~^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$~', $path) !== 1
    ) {
        return '';
    }

    return $path;
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

try {
    if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
        throw new RuntimeException('Unable to create the package test directory.');
    }

    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    file_put_contents($keyPath, base64_encode($secretKey) . "\n", LOCK_EX);
    $version = Core::VERSION;
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update.php')
        . ' --version=' . escapeshellarg($version)
        . ' --minimum-version=' . escapeshellarg($version)
        . ' --allow-dirty --without-migrations'
        . ' --output=' . escapeshellarg($output)
        . ' --key=' . escapeshellarg($keyPath);
    exec($command . ' 2>&1', $buildOutput, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Package builder failed: ' . implode(' ', $buildOutput));
    }

    $package = $output . '/tinycat-' . $version . '.zip';
    $manifestPath = $output . '/tinycat-update.json';
    $signaturePath = $output . '/tinycat-update.sig';
    $manifestJson = (string) file_get_contents($manifestPath);
    $signature = base64_decode(trim((string) file_get_contents($signaturePath)), true);

    if (!is_string($signature) || !sodium_crypto_sign_verify_detached($signature, $manifestJson, $publicKey)) {
        $failures[] = 'Generated artifact signature could not be verified.';
    }

    $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);

    if (($manifest['version'] ?? null) !== $version || ($manifest['minimum_version'] ?? null) !== $version) {
        $failures[] = 'Artifact version boundary does not match the monolith baseline.';
    }

    if (($manifest['migrations'] ?? null) !== []) {
        $failures[] = 'Stage 1 artifact fixture unexpectedly declares migrations.';
    }

    $archive = new PharData($package);
    $iterator = new RecursiveIteratorIterator($archive);

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen('phar://' . $package) + 1));
        $names[] = $relative;

        if ($managedPath($relative) !== $relative) {
            $failures[] = "Unsafe artifact path: {$relative}";
        }

        if (preg_match('~^(?:\.git|\.github|dist|storage|tests|tools|uploads|vendor)(?:/|$)~', $relative) === 1) {
            $failures[] = "Development or private path in artifact: {$relative}";
        }

        $expectedHash = $manifest['files'][$relative] ?? null;

        if (!is_string($expectedHash) || hash('sha256', (string) $entry->getContent()) !== $expectedHash) {
            $failures[] = "Artifact file hash mismatch: {$relative}";
        }
    }

    sort($names, SORT_STRING);
    $manifestNames = array_keys((array) ($manifest['files'] ?? []));
    sort($manifestNames, SORT_STRING);

    if ($manifestNames !== $names) {
        $failures[] = 'Manifest and archive file inventories differ.';
    }

    foreach (['composer.json', 'composer.lock', 'phpstan.neon', 'phpstan-baseline.neon', 'config.php'] as $file) {
        if (in_array($file, $names, true)) {
            $failures[] = "Development or private file in artifact: {$file}";
        }
    }

    foreach (['App/Core.php', 'App/functions.php', 'App/PackageManager.php', 'index.php', 'scheduled-tasks.php'] as $required) {
        if (!in_array($required, $names, true)) {
            $failures[] = "Required monolith runtime file missing from artifact: {$required}";
        }
    }

    unset($entry, $iterator, $archive);
    sodium_memzero($secretKey);
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
} finally {
    if (is_dir($temporaryRoot)) {
        $removeTree($temporaryRoot);
    }
}

if ($failures !== []) {
    foreach (array_unique($failures) as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS signed monolith artifact boundary (' . count($names) . " runtime files)\n";
