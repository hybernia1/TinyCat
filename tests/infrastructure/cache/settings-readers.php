<?php
declare(strict_types=1);
require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php';

use TinyCat\Support\{Cache, Config};
use TinyCat\Core\Settings\Store;

Config::configure(new \TinyCat\Core\Runtime\Configuration(['install' => ['complete' => true], 'database' => ['driver' => 'mysql']]));
Cache::put('core_autoload_settings', ['site.public_enabled' => true], 3600);
$directory = (string) getenv('TINYCAT_CACHE_DIRECTORY');
$processes = [];
$start = static function (string $mode) use ($directory, &$processes): void {
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/cache/settings-reader.php', $directory, $mode], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/' . $mode . '.log', 'a'], 2 => ['file', $directory . '/' . $mode . '.log', 'a']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start settings coordination worker.'); }
    fclose($pipes[0]); $processes[] = $process;
};
$wait = static function (string $name) use ($directory): void {
    $deadline = microtime(true) + 10;
    while (!is_file($directory . '/' . $name)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Settings coordination barrier timed out: ' . $name); }
        usleep(1000);
    }
};
try {
    Cache::synchronized('core_autoload_settings', static function () use ($start, $wait, $directory): void {
        $start('read'); $wait('read.done');
        if (file_get_contents($directory . '/read.done') !== 'ok') { throw new RuntimeException('Warm settings readers must run concurrently under shared coordination.'); }
        $start('write'); $wait('write.ready');
        usleep(100000);
        if (is_file($directory . '/write.done')) { throw new RuntimeException('Invalidation must wait for the shared readers.'); }
    }, shared: true);
    $wait('write.done');
    if (Store::setting('site.public_enabled') !== true || (new ReflectionProperty(\TinyCat\Database\Connection::class, 'pdo'))->getValue() !== null) { throw new RuntimeException('A warm settings snapshot must not open a database connection.'); }
} finally {
    foreach ($processes as $process) { if (proc_get_status($process)['running']) { proc_terminate($process); } proc_close($process); }
}
echo "PASS concurrent warm settings readers, exclusive invalidation and deferred DB connection\n";
