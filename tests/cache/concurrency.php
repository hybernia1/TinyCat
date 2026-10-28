<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/autoload.php';

use TinyCat\Support\Cache;
use TinyCat\Support\Config;

if (($argv[1] ?? '') === 'memcached') { putenv('TINYCAT_TEST_CACHE_BACKEND=memcached'); }
$backend = getenv('TINYCAT_TEST_CACHE_BACKEND') ?: 'filesystem';
$configure = static function (string $directory) use ($backend): void {
    foreach (['driver', 'memcachedConfig', 'memcachedClient'] as $property) { (new ReflectionProperty(Cache::class, $property))->setValue(null, null); }
    (new ReflectionProperty(Cache::class, 'memcachedInitialized'))->setValue(null, false);
    (new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
    (new ReflectionProperty(Config::class, 'config'))->setValue(null, ['cache' => ['directory' => $directory, 'driver' => $backend, 'memcached' => ['prefix' => 'tinycat:concurrency:' . hash('sha256', $directory) . ':']]]);
};

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $base, $scenario, $id] = $argv;
    $configure($base . '/' . $scenario . '-cache');
    touch($base . '/' . $scenario . '-' . $id . '.ready');
    $deadline = microtime(true) + 10;
    while (!is_file($base . '/' . $scenario . '.start')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Worker start barrier timed out.'); }
        usleep(1000);
    }
    $value = Cache::rememberArray('shared', 1, static function () use ($base, $scenario): array {
        $counter = fopen($base . '/' . $scenario . '.count', 'c+');
        flock($counter, LOCK_EX);
        $count = (int) stream_get_contents($counter);
        ftruncate($counter, 0); rewind($counter); fwrite($counter, (string) ($count + 1));
        flock($counter, LOCK_UN); fclose($counter);
        usleep(250000);
        return ['value' => 'new'];
    });
    echo json_encode($value, JSON_THROW_ON_ERROR);
    exit;
}

$base = (string) getenv('TINYCAT_CACHE_DIRECTORY') . '/concurrency-' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);
$checks = 0;
$expect = static function (bool $value, string $message) use (&$checks): void {
    if (!$value) { throw new RuntimeException($message); }
    $checks++;
};
foreach (['cold', 'stale'] as $scenario) {
    $configure($base . '/' . $scenario . '-cache');
    if ($scenario === 'stale') {
        Cache::put('shared', ['value' => 'old']);
        if ($backend === 'memcached') {
            if (!Cache::diagnostics()['memcached']['available']) { throw new RuntimeException('Memcached concurrency requires a reachable server.'); }
            $client = (new ReflectionMethod(Cache::class, 'memcachedClient'))->invoke(null);
            $key = (new ReflectionMethod(Cache::class, 'memcachedKey'))->invoke(null, 'shared');
            $payload = json_decode($client->get($key), true, flags: JSON_THROW_ON_ERROR);
            $payload['written_at'] = time() - 10;
            $client->set($key, json_encode($payload, JSON_THROW_ON_ERROR), 3600);
        } else {
            touch(Cache::file('shared.json'), time() - 10);
        }
    }
    $processes = [];
    for ($id = 0; $id < 4; $id++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $base, $scenario, (string) $id], [
            0 => ['pipe', 'r'], 1 => ['file', $base . '/' . $scenario . '-' . $id . '.json', 'w'],
            2 => ['file', $base . '/' . $scenario . '-' . $id . '.error', 'w'],
        ], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start cache worker.'); }
        fclose($pipes[0]); $processes[] = $process;
    }
    try {
        $deadline = microtime(true) + 10;
        while (count(glob($base . '/' . $scenario . '-*.ready')) < 4) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Workers did not become ready.'); }
            usleep(1000);
        }
        touch($base . '/' . $scenario . '.start');
        $results = [];
        foreach ($processes as $id => $process) {
            $exit = proc_close($process); unset($processes[$id]);
            $expect($exit === 0, 'Cache worker failed: ' . file_get_contents($base . '/' . $scenario . '-' . $id . '.error'));
            $results[] = json_decode(file_get_contents($base . '/' . $scenario . '-' . $id . '.json'), true, flags: JSON_THROW_ON_ERROR);
        }
        $expect((int) file_get_contents($base . '/' . $scenario . '.count') === 1, 'Concurrent misses must compute exactly once.');
        $expect(Cache::get('shared', 1) === ['value' => 'new'], 'The producer publishes a fresh value.');
        $values = array_column($results, 'value');
        $expect(in_array('new', $values, true), 'At least the producer receives the new value.');
        $expect($scenario === 'cold' ? count(array_unique($values)) === 1 : in_array('old', $values, true), 'Cold requests wait; refresh contenders can serve stale data.');
    } finally {
        foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
    }
}
$configure($base . '/exceptions-cache');
try { Cache::rememberArray('failed', 300, static function (): array { throw new RuntimeException('producer failed'); }); }
catch (RuntimeException $exception) { $expect($exception->getMessage() === 'producer failed', 'Producer exceptions propagate.'); }
$expect(Cache::rememberArray('failed', 300, static fn (): array => ['recovered']) === ['recovered'], 'Failed producers release their locks.');
$expect(Cache::rememberArray('empty', 300, static fn (): array => []) === [], 'Empty arrays are cached.');
$expect(Cache::rememberArray('empty', 300, static function (): array { throw new RuntimeException('Empty cache entry recomputed.'); }) === [], 'Empty arrays are cache hits.');
$configure($base . '/unavailable-cache');
mkdir(Cache::directory(), 0700, true); file_put_contents(Cache::directory() . '/locks', 'not a directory');
$expect(Cache::rememberArray('fallback', 300, static fn (): array => ['available']) === ['available'], 'Unavailable locking preserves computed results.');
echo 'PASS ' . $backend . ' concurrent cache miss, stale refresh and failure recovery (' . $checks . " checks)\n";
