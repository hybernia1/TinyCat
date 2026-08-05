<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$directory = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? (string) $argv[1]
    : $root . DIRECTORY_SEPARATOR . 'dist';

if (!str_starts_with($directory, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $directory) !== 1) {
    $directory = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory);
}

$manifestPath = $directory . DIRECTORY_SEPARATOR . 'tinycat-update.json';
$signaturePath = $directory . DIRECTORY_SEPARATOR . 'tinycat-update.sig';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Update manifest not found.\n");
    exit(1);
}

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Invalid update manifest: {$exception->getMessage()}\n");
    exit(1);
}

$packagePath = $directory . DIRECTORY_SEPARATOR . basename((string) ($manifest['package'] ?? ''));
define('TINYCAT', true);
require_once $root . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'functions.php';

try {
    $verified = Updater::verifyLocalPackage($manifestPath, $signaturePath, $packagePath);
    fwrite(STDOUT, 'Verified TinyCat ' . (string) ($verified['version'] ?? '') . ' update package.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
