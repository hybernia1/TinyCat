<?php
declare(strict_types=1);

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
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('Cache facade preserves fresh and stale values', static function () use ($expect): void {
    $key = 'cache_test_' . bin2hex(random_bytes(12));

    try {
        $expect(Cache::put($key, ['value' => 'cache']));
        $expect(Cache::read($key) === ['value' => 'cache']);
        $expect(Cache::fresh($key));
    } finally {
        Cache::forget($key);
    }
});

$test('generated cache files remain disk-backed', static function () use ($expect): void {
    $namespace = 'cache-test-' . bin2hex(random_bytes(8));
    $fileName = 'asset.txt';
    $file = Cache::file($fileName, $namespace);

    try {
        $expect(Cache::writeFile($fileName, 'generated', $namespace));
        $expect(is_file($file));
        $expect(file_get_contents($file) === 'generated');
    } finally {
        @unlink($file);
        @rmdir(dirname($file));
    }
});

$test('cache diagnostics expose a supported driver', static function () use ($expect): void {
    $diagnostics = Cache::diagnostics();

    $expect(in_array($diagnostics['driver'], ['filesystem', 'memcached'], true));
    $expect(is_bool($diagnostics['available']));
});

$test('cached autoload settings omit secret values', static function () use ($expect): void {
    $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database->exec(
        'CREATE TABLE settings (
            setting_key TEXT PRIMARY KEY,
            setting_group TEXT NOT NULL,
            setting_value TEXT NULL,
            setting_type TEXT NOT NULL,
            autoload INTEGER NOT NULL
        )'
    );
    $insert = $database->prepare(
        'INSERT INTO settings (setting_key, setting_group, setting_value, setting_type, autoload)
         VALUES (?, ?, ?, ?, 1)'
    );
    $insert->execute(['site.name', 'site', 'Cache fixture', 'string']);
    $insert->execute(['cron.token', 'security', 'cron-secret', 'string']);
    $insert->execute(['security.captcha.secret_key', 'security', 'captcha-secret', 'string']);
    $insert->execute(['email.smtp', 'email', '{"password":"smtp-secret"}', 'json']);
    Cache::forget('core_autoload_settings');
    Core::setDb($database);

    try {
        $expect(Core::setting('site.name') === 'Cache fixture');
        $settings = Cache::read('core_autoload_settings');

        $expect(is_array($settings), 'Expected the autoload settings cache to be populated.');
        $expect(($settings['site.name'] ?? null) === 'Cache fixture', 'Public autoload setting was not cached.');

        foreach (['cron.token', 'security.captcha.secret_key', 'email.smtp'] as $key) {
            $expect(!array_key_exists($key, $settings), 'Sensitive setting was stored in the cache.');
        }
    } finally {
        Cache::forget('core_autoload_settings');
    }
});

echo "\nCache tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
