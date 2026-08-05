<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/functions.php';

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
    if (!$condition) {
        throw new RuntimeException($message);
    }
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
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            $removeTree($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
};

$test('bundled bots load through their manifest', static function () use ($expect): void {
    $available = ExtensionLoader::available();
    $loaded = ExtensionLoader::loaded();

    $expect(isset($available['bots'], $loaded['bots']));
    $expect(($loaded['bots']['version'] ?? '') === '1.0.0');
    $expect(($loaded['bots']['legacy_version'] ?? '') === '1.0.0');
    $expect(($loaded['bots']['entry'] ?? '') === 'bootstrap.php');
    $expect(($available['bots']['requested_enabled'] ?? null) === true);
    $expect(($available['bots']['enabled'] ?? null) === true);
    $expect(ExtensionRegistry::has('bots'));
    $expect(class_exists(Bots::class, false));
    $expect(class_exists(BotAdmin::class, false));
});

$test('bots have no legacy runtime copies in core', static function () use ($expect): void {
    foreach ([
        'App/BotAdmin.php',
        'Public/admin/bot-accounts.php',
        'Public/admin/bot.php',
        'Public/admin/bots.php',
        'Public/admin/bots/accounts.php',
        'Public/admin/bots/list.php',
        'Public/modals/bot-account-create.php',
        'Public/modals/bot-account-filter.php',
        'Public/modals/bot-source-filter.php',
        'Public/modals/bot-source.php',
        'Public/parts/admin/bots/accounts.php',
        'Public/parts/admin/bots/sources.php',
    ] as $legacyPath) {
        $expect(!is_file(base_path($legacyPath)), 'Legacy Bots file remains in core: ' . $legacyPath);
    }

    $expect(is_file(ExtensionRegistry::file('bots', 'bootstrap.php')));
});

$test('extension state overrides accept only boolean slug maps', static function () use ($expect): void {
    $method = new ReflectionMethod(ExtensionLoader::class, 'normalizeStateOverrides');
    $states = $method->invoke(null, [
        'bots' => false,
        'sample-plugin' => true,
        'invalid slug' => true,
        'string-state' => '0',
    ]);

    $expect($states === [
        'bots' => false,
        'sample-plugin' => true,
    ]);
});

$test('extension state controls runtime registration', static function () use ($expect): void {
    foreach (['enabled', 'disabled'] as $scenario) {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'state.php')
            . ' ' . escapeshellarg($scenario);
        exec($command . ' 2>&1', $output, $exitCode);
        $expect($exitCode === 0, implode(PHP_EOL, $output));
    }
});

$test('extension lifecycle versions and migrations are restart-safe', static function () use ($expect): void {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'lifecycle.php');
    exec($command . ' 2>&1', $output, $exitCode);
    $expect($exitCode === 0, implode(PHP_EOL, $output));
});

$test('bots keep their registered integration points', static function () use ($expect): void {
    $expect(in_array('bot_sources', ExtensionRegistry::requiredTables(), true));
    $expect(isset(ExtensionRegistry::scheduledTasks()['feeds']));
    $expect((ExtensionRegistry::scheduledTasks()['feeds']['admin']['title'] ?? '') === 'cron.tasks.feeds');
    $expect(!UserRoles::allowsLogin('bot'));
    $expect(!in_array('bot', UserRoles::rolesWith('appears_in_people_rankings'), true));
    $expect(t('bots.title', [], 'cs') === 'Boti');
    $expect(t('install.purpose_bot_sources', [], 'en') === 'RSS and Atom publishing rules for bot accounts.');
    $expect(is_file(ExtensionRegistry::file('bots', 'Controllers/detail.php')));
});

$test('extensions cannot replace existing user roles', static function () use ($expect, $expectFailure): void {
    $expectFailure(static function (): void {
        UserRoles::register('admin', []);
    });
    $expectFailure(static function (): void {
        UserRoles::register('bot', []);
    });
    $expect(UserRoles::allowsLogin('admin'));
    $expect(!UserRoles::allowsLogin('bot'));
});

$test('legacy bots are adopted without changing their data', static function () use ($expect): void {
    $status = ExtensionLifecycle::all()['bots'] ?? [];
    $freshVersions = ExtensionLifecycle::freshInstallVersions();

    $expect(!empty($status['installed']));
    $expect(($status['installed_version'] ?? '') === '1.0.0');
    $expect(($freshVersions['bots'] ?? '') === '1.0.0');
});

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tinycat-extension-loader-' . bin2hex(random_bytes(8));

try {
    $test('a release may contain no extensions directory', static function () use ($expect, $temporaryRoot): void {
        $expect(ExtensionLoader::discover($temporaryRoot . DIRECTORY_SEPARATOR . 'Missing') === []);
    });

    mkdir($temporaryRoot, 0777, true);
    $sampleRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'Sample';
    mkdir($sampleRoot);
    file_put_contents($sampleRoot . DIRECTORY_SEPARATOR . 'entry.php', "<?php\n");
    file_put_contents($sampleRoot . DIRECTORY_SEPARATOR . 'extension.json', json_encode([
        'schema' => 1,
        'slug' => 'sample',
        'name' => 'Sample',
        'version' => '1.2.3',
        'requires' => ['tinycat' => '1.0.0'],
        'entry' => 'entry.php',
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
        'requires' => ['tinycat' => '999.0.0'],
        'entry' => 'entry.php',
        'autoload' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $test('manifest discovery validates and normalizes metadata', static function () use ($expect, $temporaryRoot): void {
        $discovered = ExtensionLoader::discover($temporaryRoot);

        $expect(isset($discovered['sample']));
        $expect(($discovered['sample']['minimum_tinycat'] ?? '') === '1.0.0');
        $expect(($discovered['sample']['compatible'] ?? null) === true);
        $expect(($discovered['sample']['autoload'] ?? null) === false);
        $expect(is_file((string) ($discovered['sample']['entry_path'] ?? '')));
        $expect(($discovered['future']['compatible'] ?? null) === false);
    });

    $test('manifest discovery rejects entry traversal', static function () use ($expectFailure, $temporaryRoot, $sampleRoot): void {
        $manifestPath = $sampleRoot . DIRECTORY_SEPARATOR . 'extension.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        $manifest['entry'] = '../outside.php';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $expectFailure(static fn (): array => ExtensionLoader::discover($temporaryRoot));
    });
} finally {
    $removeTree($temporaryRoot);
}

echo "\nExtension loader tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
