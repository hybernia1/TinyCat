<?php
declare(strict_types=1);

use \TinyCat\Core\Updates\Manager;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$directory = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? (string) $argv[1]
    : $root . DIRECTORY_SEPARATOR . 'dist';

if (!str_starts_with($directory, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $directory) !== 1) {
    $directory = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory);
}

$product = 'tinycat.social';
foreach ($argv as $argument) { if (str_starts_with($argument, '--product=')) { $product = substr($argument, strlen('--product=')); } }
if (preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $product) !== 1) { fwrite(STDERR, "Invalid product identity.\n"); exit(1); }
$suffix = in_array('--fresh-install', $argv, true) ? '-install' : '-update';
$matches = [];
foreach (glob($directory . DIRECTORY_SEPARATOR . '*' . $suffix . '.json') ?: [] as $candidate) {
    try { $data = json_decode((string) file_get_contents($candidate), true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { continue; }
    // Discovery reads data only. Trust is established by signature and archive verification below.
    if (is_array($data) && ($data['distribution']['product'] ?? 'tinycat.social') === $product) { $matches[$candidate] = $data; }
}
if (count($matches) !== 1) {
    fwrite(STDERR, "Expected exactly one {$product}{$suffix} manifest.\n");
    exit(1);
}
$manifestPath = array_key_first($matches);
$manifest = $matches[$manifestPath];
$signaturePath = substr($manifestPath, 0, -5) . '.sig';

$packagePath = $directory . DIRECTORY_SEPARATOR . basename((string) ($manifest['package'] ?? ''));
if (!defined('TINYCAT')) { define('TINYCAT', true); }
require_once $root . '/App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

try {
    $verified = Manager::verifyLocalPackage($manifestPath, $signaturePath, $packagePath);
    if (($verified['distribution']['product'] ?? 'tinycat.social') !== $product) { throw new RuntimeException('The verified product does not match the requested product.'); }
    $stem = $verified['distribution']['artifact'] ?? 'tinycat';
    if (basename($manifestPath) !== $stem . $suffix . '.json') { throw new RuntimeException('The manifest filename does not match the signed artifact identity.'); }
    fwrite(STDOUT, 'Verified TinyCat ' . (string) ($verified['version'] ?? '') . ' update package.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
