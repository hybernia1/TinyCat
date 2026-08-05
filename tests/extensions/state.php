<?php
declare(strict_types=1);

use TinyCat\Extension\Loader;
use TinyCat\Extension\Registry;

define('TINYCAT', true);

$root = dirname(__DIR__, 2);
require_once $root . '/App/Core.php';
require_once $root . '/App/autoload.php';

$scenario = (string) ($argv[1] ?? '');
if (!in_array($scenario, ['enabled', 'disabled', 'uninstalled', 'version-mismatch'], true)) {
    fwrite(STDERR, "Expected enabled, disabled, uninstalled, or version-mismatch scenario.\n");
    exit(2);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tinycat-extension-state-' . bin2hex(random_bytes(8));
$sample = $temporary . DIRECTORY_SEPARATOR . 'Sample';

try {
    mkdir($sample, 0777, true);
    file_put_contents($sample . DIRECTORY_SEPARATOR . 'bootstrap.php', <<<'PHP'
<?php
TinyCat\Extension\Registry::register('sample', [
    'root' => __DIR__,
    'required_tables' => ['sample_data'],
]);
PHP);
    file_put_contents($sample . DIRECTORY_SEPARATOR . 'extension.json', json_encode([
        'schema' => 1,
        'slug' => 'sample',
        'name' => 'Sample',
        'version' => '1.0.0',
        'requires' => ['tinycat' => '1.0.0', 'php' => '8.4.0'],
        'entry' => 'bootstrap.php',
        'migrations' => [],
        'autoload' => in_array($scenario, ['uninstalled', 'version-mismatch'], true),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $expected = $scenario === 'enabled';
    $installedVersions = match ($scenario) {
        'enabled', 'disabled' => ['sample' => '1.0.0'],
        'version-mismatch' => ['sample' => '0.9.0'],
        default => [],
    };
    $stateOverrides = match ($scenario) {
        'enabled' => ['sample' => true],
        'disabled' => ['sample' => false],
        default => [],
    };
    Loader::boot($temporary, $stateOverrides, $installedVersions);
    $available = Loader::available()['sample'] ?? [];
    $requestedEnabled = $expected;
    $valid = ($available['requested_enabled'] ?? null) === $requestedEnabled
        && ($available['enabled'] ?? null) === $expected
        && Registry::has('sample') === $expected
        && in_array('sample_data', Registry::requiredTables(), true) === $expected;

    if (!$valid) {
        throw new RuntimeException('Extension state did not produce the expected runtime registration.');
    }

    echo 'PASS extension state: ' . $scenario . PHP_EOL;
} finally {
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temporary);
    }
}
