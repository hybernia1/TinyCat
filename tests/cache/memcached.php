<?php
declare(strict_types=1);
require __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/autoload.php';
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
    $expect(!is_file(Cache::file('replacement.json')), 'Successful primary write removes old fallback.');
    $expect(Cache::get('replacement') === ['new'], 'Primary value must be returned after recovery.');
    $key = (new ReflectionMethod(Cache::class, 'memcachedKey'))->invoke(null, 'replacement');
    $prefix = Config::get('cache.memcached.prefix');
    $expected = $prefix . hash('sha256', \TinyCat\Support\Version::CURRENT . ':' . getenv('TINYCAT_CACHE_DIRECTORY') . ':replacement');
    $expect($key === $expected && is_string($client->get($expected)), 'Primary keys must include the release and isolated test namespace.');
    $expect(Cache::forget('replacement') && Cache::get('replacement') === null, 'Invalidation removes both copies.');
    Cache::put('expiry', ['stale'], 1, 1);
    sleep(3);
    $expect(Cache::read('expiry') === null, 'Memcached expires stale data.');
    // Simulate an unavailable primary with a refused loopback port.
    $offline = new Memcached(); $offline->addServer('127.0.0.1', 1);
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $offline);
    $expect(Cache::put('offline', ['available']), 'Unavailable primary falls back to disk.');
    $expect(Cache::get('offline') === ['available'], 'Fallback remains readable during outage.');
    (new ReflectionProperty(Cache::class, 'memcachedClient'))->setValue(null, $client);
    $expect(Cache::get('offline') === ['available'], 'Fallback remains readable after server recovery.');
    echo "PASS real Memcached fallback, recovery, invalidation and expiry ({$checks} checks)\n";
} finally {
    foreach (['replacement', 'expiry', 'offline'] as $key) { Cache::forget($key); }
}

$process = proc_open([PHP_BINARY, __DIR__ . '/concurrency.php', 'memcached'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Unable to start Memcached concurrency checks.'); }
fclose($pipes[0]);
if (proc_close($process) !== 0) { throw new RuntimeException('Memcached concurrency checks failed.'); }
