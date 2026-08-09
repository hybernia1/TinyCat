<?php
declare(strict_types=1);

use TinyCat\Extension\Lifecycle;
use TinyCat\Extension\Loader;
use TinyCat\Extension\Registry;
use TinyCat\Extension\Store;
use TinyCat\Extension\Assets;
use TinyCat\Sitemap;

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

$passed = 0;
$failed = 0;

$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL {$name}: {$exception->getMessage()}\n";
    }
};

$expect = static function (bool $condition, string $message = 'Expectation failed.'): void {
    if (!$condition) throw new RuntimeException($message);
};

$expectFailure = static function (callable $callback): void {
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Expected operation was accepted.');
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) $removeTree($child);
        else unlink($child);
    }
    rmdir($path);
};

$test('module classes are autoloaded from their namespaces', static function () use ($expect): void {
    $expect(class_exists(Loader::class));
    $expect(class_exists(Store::class));
    $expect(class_exists(Sitemap::class));
    $expect(class_exists(Assets::class));
    $expect(class_exists(Lifecycle::class));
    $expect(class_exists(Registry::class));
    $expect(class_exists(TinyCat\Update\Manager::class));
    $expect(class_exists(TinyCat\Update\MigrationRegistry::class));
    $expect(!class_exists('ExtensionRegistry', false));
    $expect(!class_exists('ExtensionLoader', false));
    $expect(!class_exists('ExtensionStore', false));
});

$test('core release boots without functional extensions', static function () use ($expect): void {
    $expect(Loader::available() === []);
    $expect(Loader::loaded() === []);
    $expect(Registry::slugs() === []);
    $expect(Lifecycle::freshInstallVersions() === []);
    $expect(is_file(base_path('Extensions/.htaccess')));
    $expect(!is_dir(base_path('Extensions/Bots')));
});

$test('official store is linked without coupling core to Bots', static function () use ($expect): void {
    $expect(Store::repository() === 'hybernia1/TinyCat-Extensions');
    $expect(!is_file(base_path('Extensions/Bots/extension.json')));
    $expect(!class_exists('Bots', false));
    $expect(UserRoles::profileSchemaType('bot') === 'Person');
});

$test('store catalog normalizes signed package metadata', static function () use ($expect): void {
    $validate = new ReflectionMethod(Store::class, 'validateCatalog');
    $package = 'tinycat-extension-sample-1.0.0.zip';
    $catalog = $validate->invoke(null, [
        'schema' => 1,
        'extensions' => [[
            'slug' => 'sample',
            'name' => 'Sample',
            'directory' => 'Sample',
            'version' => '1.0.0',
            'requires' => ['tinycat' => '1.0.0', 'php' => '8.4.0'],
            'descriptions' => ['en' => 'Sample extension.'],
            'homepage' => 'https://github.com/example/sample',
            'package' => $package,
            'sha256' => str_repeat('a', 64),
            'size' => 123,
            'files' => ['Sample/extension.json' => str_repeat('b', 64)],
        ]],
    ], [$package => 'https://github.com/example/sample.zip']);

    $expect(($catalog['sample']['compatible'] ?? null) === true);
    $expect(($catalog['sample']['package'] ?? '') === $package);
    $expect(count((array) ($catalog['sample']['files'] ?? [])) === 1);
});

$test('store catalog rejects paths outside an extension package', static function () use ($expectFailure): void {
    $validate = new ReflectionMethod(Store::class, 'validateCatalog');
    $package = 'tinycat-extension-sample-1.0.0.zip';
    $expectFailure(static fn () => $validate->invoke(null, [
        'schema' => 1,
        'extensions' => [[
            'slug' => 'sample',
            'name' => 'Sample',
            'directory' => 'Sample',
            'version' => '1.0.0',
            'requires' => ['tinycat' => '1.0.0', 'php' => '8.4.0'],
            'homepage' => 'https://github.com/example/sample',
            'package' => $package,
            'sha256' => str_repeat('a', 64),
            'size' => 123,
            'files' => ['Sample/../outside.php' => str_repeat('b', 64)],
        ]],
    ], [$package => 'https://github.com/example/sample.zip']));
});

$test('store catalog rejects case and file-directory path collisions', static function () use ($expectFailure): void {
    $validate = new ReflectionMethod(Store::class, 'validateFiles');
    $hash = str_repeat('b', 64);

    $expectFailure(static fn () => $validate->invoke(null, [
        'Sample/extension.json' => $hash,
        'Sample/EXTENSION.json' => $hash,
    ], 'Sample'));
    $expectFailure(static fn () => $validate->invoke(null, [
        'Sample/extension.json' => $hash,
        'Sample/extension.json/config.php' => $hash,
    ], 'Sample'));
});

$test('extension state overrides accept only boolean slug maps', static function () use ($expect): void {
    $method = new ReflectionMethod(Loader::class, 'normalizeStateOverrides');
    $states = $method->invoke(null, [
        'sample' => false,
        'sample-plugin' => true,
        'invalid slug' => true,
        'string-state' => '0',
    ]);
    $expect($states === ['sample' => false, 'sample-plugin' => true]);
});

$test('only the recorded installed version may register an extension', static function () use ($expect): void {
    foreach (['enabled', 'disabled', 'uninstalled', 'version-mismatch'] as $scenario) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'state.php')
            . ' ' . escapeshellarg($scenario);
        exec($command . ' 2>&1', $output, $exitCode);
        $expect($exitCode === 0, implode(PHP_EOL, $output));
    }
});

$test('extension lifecycle versions and migrations are restart-safe', static function () use ($expect): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'lifecycle.php');
    exec($command . ' 2>&1', $output, $exitCode);
    $expect($exitCode === 0, implode(PHP_EOL, $output));
});

$test('extension sitemap and asset capabilities are validated', static function () use ($expect): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'capabilities.php');
    exec($command . ' 2>&1', $output, $exitCode);
    $expect($exitCode === 0, implode(PHP_EOL, $output));
});

$test('extension uninstall preserves or clears migration history by mode', static function () use ($expect): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'uninstall.php');
    exec($command . ' 2>&1', $output, $exitCode);
    $expect($exitCode === 0, implode(PHP_EOL, $output));
    $skipped = array_filter($output, static fn (string $line): bool => str_starts_with($line, 'SKIP extension uninstall:'));
    $expect(in_array('PASS extension uninstall lifecycle', $output, true) || $skipped !== [], implode(PHP_EOL, $output));
});

$test('extensions cannot replace core user roles', static function () use ($expect, $expectFailure): void {
    $expectFailure(static fn () => UserRoles::register('admin', []));
    UserRoles::register('sample-extension-role', ['allows_login' => false]);
    $expectFailure(static fn () => UserRoles::register('sample-extension-role', []));
    $expect(UserRoles::allowsLogin('admin'));
    $expect(!UserRoles::allowsLogin('sample-extension-role'));
});

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tinycat-extension-loader-' . bin2hex(random_bytes(8));

try {
    $test('a release may contain no extensions directory', static function () use ($expect, $temporaryRoot): void {
        $expect(Loader::discover($temporaryRoot . DIRECTORY_SEPARATOR . 'Missing') === []);
    });

    mkdir($temporaryRoot, 0777, true);
    $sampleRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'Sample';
    mkdir($sampleRoot);
    file_put_contents($sampleRoot . DIRECTORY_SEPARATOR . 'entry.php', "<?php\n");
    file_put_contents(
        $sampleRoot . DIRECTORY_SEPARATOR . 'uninstall.php',
        "<?php\nreturn static fn (PDO \$database, array \$context): array => ['data_removed' => false];\n"
    );
    file_put_contents($sampleRoot . DIRECTORY_SEPARATOR . 'extension.json', json_encode([
        'schema' => 1,
        'slug' => 'sample',
        'name' => 'Sample',
        'version' => '1.2.3',
        'requires' => ['tinycat' => '1.0.0', 'php' => '8.4.0'],
        'entry' => 'entry.php',
        'migrations' => [],
        'uninstall' => [
            'handler' => 'uninstall.php',
            'options' => [[
                'id' => 'keep',
                'labels' => ['cs' => 'Zachovat data', 'en' => 'Keep data'],
                'descriptions' => ['cs' => 'Data zůstanou zachována.', 'en' => 'Stored data is preserved.'],
                'danger' => false,
                'recommended' => true,
            ]],
        ],
        'autoload' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $futureRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'Future';
    mkdir($futureRoot);
    file_put_contents($futureRoot . DIRECTORY_SEPARATOR . 'entry.php', "<?php\n");
    file_put_contents($futureRoot . DIRECTORY_SEPARATOR . 'extension.json', json_encode([
        'schema' => 1,
        'slug' => 'future',
        'name' => 'Future',
        'version' => '1.0.0',
        'requires' => ['tinycat' => '999.0.0', 'php' => '8.4.0'],
        'entry' => 'entry.php',
        'migrations' => [],
        'autoload' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $test('manifest discovery validates compatibility metadata', static function () use ($expect, $temporaryRoot): void {
        $discovered = Loader::discover($temporaryRoot);
        $expect(($discovered['sample']['minimum_tinycat'] ?? '') === '1.0.0');
        $expect(($discovered['sample']['minimum_php'] ?? '') === '8.4.0');
        $expect(($discovered['sample']['compatible'] ?? null) === true);
        $expect(($discovered['sample']['autoload'] ?? null) === false);
        $expect(($discovered['sample']['uninstall']['handler'] ?? '') === 'uninstall.php');
        $expect(is_file((string) ($discovered['sample']['uninstall']['handler_path'] ?? '')));
        $expect(($discovered['sample']['uninstall']['options'][0]['id'] ?? '') === 'keep');
        $expect(($discovered['future']['compatible'] ?? null) === false);
    });

    $test('public manifests do not expose executable paths', static function () use ($expect, $temporaryRoot): void {
        $manifest = Loader::discover($temporaryRoot)['sample'];
        $publicManifest = (new ReflectionMethod(Loader::class, 'publicManifest'))->invoke(null, $manifest);
        $expect(!isset($publicManifest['entry_path']));
        $expect(!isset($publicManifest['uninstall']['handler_path']));
        $expect(($publicManifest['uninstall']['handler'] ?? '') === 'uninstall.php');
    });

    $test('manifest discovery rejects duplicate uninstall options', static function () use ($expectFailure, $temporaryRoot, $sampleRoot): void {
        $manifestPath = $sampleRoot . DIRECTORY_SEPARATOR . 'extension.json';
        $original = (string) file_get_contents($manifestPath);
        $manifest = json_decode($original, true, 32, JSON_THROW_ON_ERROR);
        $manifest['uninstall']['options'][] = $manifest['uninstall']['options'][0];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        try {
            $expectFailure(static fn (): array => Loader::discover($temporaryRoot));
        } finally {
            file_put_contents($manifestPath, $original);
        }
    });

    $test('manifest discovery rejects uninstall handler traversal', static function () use ($expectFailure, $temporaryRoot, $sampleRoot): void {
        $manifestPath = $sampleRoot . DIRECTORY_SEPARATOR . 'extension.json';
        $original = (string) file_get_contents($manifestPath);
        $manifest = json_decode($original, true, 32, JSON_THROW_ON_ERROR);
        $manifest['uninstall']['handler'] = '../outside.php';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        try {
            $expectFailure(static fn (): array => Loader::discover($temporaryRoot));
        } finally {
            file_put_contents($manifestPath, $original);
        }
    });

    $test('manifest discovery rejects entry traversal', static function () use ($expectFailure, $temporaryRoot, $sampleRoot): void {
        $manifestPath = $sampleRoot . DIRECTORY_SEPARATOR . 'extension.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        $manifest['entry'] = '../outside.php';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $expectFailure(static fn (): array => Loader::discover($temporaryRoot));
    });
} finally {
    $removeTree($temporaryRoot);
}

echo "\nExtension tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
