<?php
declare(strict_types=1);
require __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php';
use TinyCat\Support\Cache;
use TinyCat\Support\Config;

if (!class_exists('Memcached')) { echo "SKIP Memcached integration: extension unavailable\n"; exit(0); }
$client = new Memcached();
$client->addServer('127.0.0.1', 11211);
if (!is_string(($client->getVersion()['127.0.0.1:11211'] ?? null))) {
    echo "SKIP Memcached integration: local server unavailable\n"; exit(0);
}
(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['cache' => [
    'driver' => 'memcached', 'memcached' => ['prefix' => 'tinycat:test:' . bin2hex(random_bytes(8)) . ':', 'max_item_bytes' => 1024],
]]);
$checks = 0;
$expect = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
};
try {
    $expect(Cache::diagnostics()['memcached']['available'], 'Memcached must be reachable.');
    $expect(Cache::put('replacement', ['old']), 'Primary write failed.');
    $large = ['body' => str_repeat('x', 2048)];
    $expect(Cache::put('replacement', $large), 'Oversized item must be cached on disk.');
    $expect(Cache::get('replacement') === $large, 'Disk replacement must supersede the old primary value.');
    $expect(Cache::put('replacement', ['new']), 'Primary recovery failed.');
    $expect(!is_file(Cache::file(hash('sha256', 'replacement') . '.json')), 'Successful primary write removes old fallback.');
    $expect(Cache::get('replacement') === ['new'], 'Primary value must be returned after recovery.');
    $key = (new ReflectionMethod(Cache::class, 'memcachedKey'))->invoke(null, 'replacement');
    $prefix = Config::get('cache.memcached.prefix');
    $expected = $prefix . hash('sha256', \TinyCat\Support\Version::CURRENT . ':' . \TinyCat\Support\Paths::basePath() . ':' . Cache::directory() . ':replacement');
    $expect($key === $expected && is_string($client->get($expected)), 'Primary keys must include the release and isolated test namespace.');
    $expect(Cache::forget('replacement') && Cache::get('replacement') === null, 'Invalidation removes both copies.');
    $expect(Cache::put('expiry', ['stale'], 1, 1), 'Expiry fixture write succeeds.');
    $expiryKey = (new ReflectionMethod(Cache::class, 'memcachedKey'))->invoke(null, 'expiry');
    $expect(is_string($client->get($expiryKey)), 'Expiry fixture is stored on the real primary.');
    // The server clock advances in coarse ticks and can lag the PHP clock.
    $expiryDeadline = hrtime(true) + 10_000_000_000;
    do {
        usleep(100000);
        $expired = Cache::read('expiry') === null;
    } while (!$expired && hrtime(true) < $expiryDeadline);
    $expect($expired, 'Memcached expires stale data within the bounded deadline.');
    foreach ([2_592_000, 2_592_001] as $retention) {
        $expect(Cache::put('long-expiry', ['retained'], $retention, 0), 'Long expiry write succeeds.');
        $longKey = (new ReflectionMethod(Cache::class, 'memcachedKey'))->invoke(null, 'long-expiry');
        $expect(is_string($client->get($longKey)), 'Durations on both sides of the 30-day boundary remain on the real primary.');
    }
    // Simulate an unavailable primary with a refused loopback port.
    $offline = new Memcached(); $offline->addServer('127.0.0.1', 1);
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $offline);
    $expect(Cache::put('offline', ['available']), 'Unavailable primary falls back to disk.');
    $expect(Cache::get('offline') === ['available'], 'Fallback remains readable during outage.');
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $client);
    $expect(Cache::get('offline') === ['available'], 'Fallback remains readable after server recovery.');
    // A connection partition does not erase the primary's pre-outage value.
    $primary = (new ReflectionProperty(Cache::class, 'memcachedClient'))->getValue();
    Cache::put('partition', ['old']);
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $offline);
    // Recovery must not race the one-second filesystem timestamp boundary.
    $expect(Cache::put('partition', ['new'], 30, 0), 'Partition replacement persists locally.');
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $primary);
    $expect(Cache::get('partition') === ['new'], 'Recovered primary cannot resurrect an old value.');
    touch(Cache::file(hash('sha256', 'partition') . '.json'), time() - 33);
    $expect(Cache::get('partition') === null, 'Expired fallback cannot expose the old primary.');
    $expect(Cache::put('partition', ['reconciled']), 'Successful replacement reconciles stores.');
    $expect(Cache::get('partition') === ['reconciled'], 'Reconciled primary is readable.');
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $offline);
    $expect(Cache::forget('partition'), 'Durable local invalidation succeeds during partition.');
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $primary);
    $expect(Cache::get('partition') === null, 'Recovered primary cannot resurrect an invalidated entry.');
    $configuration = (new ReflectionProperty(Config::class, 'config'))->getValue();
    Cache::put('installation', ['first']);
    $other = $configuration;
    $other['cache']['directory'] = Cache::directory() . '/other-installation';
    (new ReflectionProperty(Config::class, 'config'))->setValue(null, $other);
    $expect(Cache::get('installation') === null, 'Configured directories isolate shared Memcached prefixes.');
    Cache::put('installation', ['second']);
    Cache::forget('installation');
    (new ReflectionProperty(Config::class, 'config'))->setValue(null, $configuration);
    $expect(Cache::get('installation') === ['first'], 'Other installation mutation preserves the first.');
    echo "PASS real Memcached fallback, recovery, invalidation and expiry ({$checks} checks)\n";
} finally {
    foreach (['replacement', 'expiry', 'long-expiry', 'offline', 'partition', 'installation'] as $key) { Cache::forget($key); }
}

$process = proc_open([PHP_BINARY, __DIR__ . '/concurrency.php', 'memcached'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Unable to start Memcached concurrency checks.'); }
fclose($pipes[0]);
if (proc_close($process) !== 0) { throw new RuntimeException('Memcached concurrency checks failed.'); }
