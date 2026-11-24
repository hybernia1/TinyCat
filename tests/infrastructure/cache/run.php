<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';

define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

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

$test('unavailable Memcached version response is handled without warnings', static function () use ($expect): void {
    if (!class_exists('Memcached')) { return; }
    $client = new class extends Memcached {
        public array|false $versions = false;
        public function getVersion(): array|false { return $this->versions; }
    };
    $clientProperty = new ReflectionProperty(\TinyCat\Support\Cache::class, 'memcachedClient');
    $initializedProperty = new ReflectionProperty(\TinyCat\Support\Cache::class, 'memcachedInitialized');
    $previousClient = $clientProperty->getValue();
    $previousInitialized = $initializedProperty->getValue();
    $clientProperty->setValue(null, $client);
    $initializedProperty->setValue(null, true);
    $available = new ReflectionMethod(\TinyCat\Support\Cache::class, 'memcachedAvailable');
    set_error_handler(static function (int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
    try {
        foreach ([false, [], ['127.0.0.1:11211' => '']] as $versions) {
            $client->versions = $versions;
            $expect($available->invoke(null) === false, 'Missing versions mean the cache is unavailable.');
        }
        $client->versions = ['127.0.0.1:11211' => '1.6.0'];
        $expect($available->invoke(null) === true, 'A server version confirms availability.');
    } finally {
        restore_error_handler();
        $clientProperty->setValue(null, $previousClient);
        $initializedProperty->setValue(null, $previousInitialized);
    }
});

$test('logical keys remain distinct on disk', static function () use ($expect): void {
    $prefix = 'collision-' . bin2hex(random_bytes(6));
    $keys = [$prefix . '/a', $prefix . ':a', $prefix . '_a'];
    try {
        foreach ($keys as $index => $key) { $expect(\TinyCat\Support\Cache::put($key, [$index])); }
        foreach ($keys as $index => $key) { $expect(\TinyCat\Support\Cache::get($key) === [$index]); }
    } finally {
        foreach ($keys as $key) { \TinyCat\Support\Cache::forget($key); }
    }
});

$test('Cache facade preserves fresh and stale values', static function () use ($expect): void {
    $key = 'cache_test_' . bin2hex(random_bytes(12));

    try {
        $expect(\TinyCat\Support\Cache::put($key, ['value' => 'cache']));
        $expect(\TinyCat\Support\Cache::read($key) === ['value' => 'cache']);
        $expect(\TinyCat\Support\Cache::fresh($key));
    } finally {
        \TinyCat\Support\Cache::forget($key);
    }
});

$test('stale expiry is enforced without cleanup', static function () use ($expect): void {
    $key = 'bounded-stale';
    \TinyCat\Support\Cache::put($key, ['old'], 60, 120);
    $file = \TinyCat\Support\Cache::file(hash('sha256', $key) . '.json');
    // Exercise the filesystem backend independently of the local configuration.
    $put = new ReflectionMethod(\TinyCat\Support\Cache::class, 'filesystemPut');
    $lookup = new ReflectionMethod(\TinyCat\Support\Cache::class, 'filesystemLookup');
    $put->invoke(null, $key, ['old'], 60, 120);
    // Move timestamps into each state instead of giving filesystem I/O a one-second window.
    // Age 90 is stale (fresh TTL 60), but remains within total retention 180.
    touch($file, time() - 90);
    $expect($lookup->invoke(null, $key)['found'], 'Allowed stale data remains readable.');
    touch($file, time() - 181);
    $expect(!$lookup->invoke(null, $key)['found'], 'Expired stale data must not be returned.');
    \TinyCat\Support\Cache::forget($key);
});

$test('generated cache files remain disk-backed', static function () use ($expect): void {
    $namespace = 'cache-test-' . bin2hex(random_bytes(8));
    $fileName = 'asset.txt';
    $file = \TinyCat\Support\Cache::file($fileName, $namespace);

    try {
        $expect(\TinyCat\Support\Cache::writeFile($fileName, 'generated', $namespace));
        $expect(is_file($file));
        $expect(file_get_contents($file) === 'generated');
    } finally {
        @unlink($file);
        @rmdir(dirname($file));
    }
});

$test('cache diagnostics expose a supported driver', static function () use ($expect): void {
    $diagnostics = \TinyCat\Support\Cache::diagnostics();

    $expect(in_array($diagnostics['driver'], ['filesystem', 'memcached'], true));
    $expect(is_bool($diagnostics['available']));
    $expect(is_bool($diagnostics['memcached']['configured'] ?? null));
    $expect(is_bool($diagnostics['memcached']['extension'] ?? null));
    $expect(is_bool($diagnostics['memcached']['available'] ?? null));
    foreach (['servers', 'uptime', 'items', 'bytes', 'limit_bytes', 'hits', 'misses', 'evictions'] as $key) {
        $expect(is_int($diagnostics['memcached']['stats'][$key] ?? null));
    }
    $expect(is_bool($diagnostics['opcache']['loaded'] ?? null));
    $expect(is_bool($diagnostics['opcache']['enabled'] ?? null));
    $expect(is_bool($diagnostics['opcache']['resettable'] ?? null));
    foreach (['memory_bytes', 'max_scripts', 'revalidate_freq'] as $key) {
        $expect(is_int($diagnostics['opcache']['configuration'][$key] ?? null));
    }
    foreach (['validate_timestamps', 'file_cache'] as $key) {
        $expect(is_bool($diagnostics['opcache']['configuration'][$key] ?? null));
    }
    foreach (['cached_scripts', 'hits', 'misses', 'used_memory', 'free_memory', 'wasted_memory'] as $key) {
        $expect(is_int($diagnostics['opcache']['stats'][$key] ?? null));
    }
    foreach (['cache_full', 'restart_pending'] as $key) {
        $expect(is_bool($diagnostics['opcache']['stats'][$key] ?? null));
    }
    $expect(is_bool(\TinyCat\Support\Cache::resetOpcache()));
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
    \TinyCat\Support\Cache::forget('core_autoload_settings');
    \TinyCat\Core\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Core\Identity\Authentication::reset(); \TinyCat\Database\Connection::set($database);

    try {
        $expect(\TinyCat\Core\Settings\Store::setting('site.name') === 'Cache fixture');
        $settings = \TinyCat\Support\Cache::read('core_autoload_settings');

        $expect(is_array($settings), 'Expected the autoload settings cache to be populated.');
        $expect(($settings['site.name'] ?? null) === 'Cache fixture', 'Public autoload setting was not cached.');

        foreach (['cron.token', 'security.captcha.secret_key', 'email.smtp'] as $key) {
            $expect(!array_key_exists($key, $settings), 'Sensitive setting was stored in the cache.');
        }
    } finally {
        \TinyCat\Support\Cache::forget('core_autoload_settings');
    }
});

$test('transactional settings saves coalesce invalidation and preserve rollback', static function () use ($expect): void {
    $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $database->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_group TEXT NOT NULL, setting_value TEXT, setting_type TEXT NOT NULL, autoload INTEGER NOT NULL)');
    \TinyCat\Database\Connection::set($database);
    \TinyCat\Core\Settings\Store::reset();
    \TinyCat\Support\Cache::forget('core_autoload_settings');
    \TinyCat\Core\Settings\Store::setSetting('site.name', 'original');
    \TinyCat\Core\Settings\Store::setSetting('cron.token', 'original-secret');
    $expect(\TinyCat\Core\Settings\Store::setting('site.name') === 'original');
    $expect(\TinyCat\Core\Settings\Store::setting('cron.token') === 'original-secret');
    \TinyCat\Support\Localization::locale('en');
    $queue = new ReflectionProperty(\TinyCat\Database\Transactions::class, 'afterCommit');
    \TinyCat\Database\Transactions::run(static function () use ($expect, $queue): void {
        foreach (['site.name' => 'updated', 'site.home_title' => 'title', 'cron.token' => 'new-secret', 'i18n.locale' => 'cs'] as $key => $value) {
            \TinyCat\Core\Settings\Store::setSetting($key, $value);
        }
        $expect(count($queue->getValue()) === 2, 'A multi-key save queues shared invalidation once and locale reset once');
        $expect(\TinyCat\Core\Settings\Store::setting('site.name') === 'original', 'Uncommitted writes do not enter the shared cache');
    });
    $expect((new ReflectionProperty(\TinyCat\Support\Localization::class, 'locale'))->getValue() === null, 'Committing locale changes resets localization');
    $expect(\TinyCat\Core\Settings\Store::setting('site.name') === 'updated');
    $expect(\TinyCat\Core\Settings\Store::setting('cron.token') === 'new-secret', 'Coalesced invalidation clears sensitive memory too');
    $cached = \TinyCat\Support\Cache::read('core_autoload_settings');
    try {
        \TinyCat\Database\Transactions::run(static function (): void {
            \TinyCat\Core\Settings\Store::setSetting('site.name', 'rolled-back');
            \TinyCat\Core\Settings\Store::setSetting('cron.token', 'rolled-back-secret');
            throw new DomainException('rollback settings');
        });
    } catch (DomainException) {}
    $expect(\TinyCat\Core\Settings\Store::setting('site.name') === 'updated'
        && \TinyCat\Core\Settings\Store::setting('cron.token') === 'new-secret', 'Rollback preserves public and sensitive settings');
    $expect(\TinyCat\Support\Cache::read('core_autoload_settings') === $cached, 'Rollback never invalidates committed cache');
    \TinyCat\Support\Cache::forget('core_autoload_settings');
});

echo "\nCache tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
