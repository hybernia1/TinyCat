<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require_once __DIR__ . '/../../support/cache-isolation.php'; define('TINYCAT', true); $source = dirname(__DIR__, 3); require $source . '/App/Core/Runtime/autoload.php';
use TinyCat\Support\{Cache, Config};
use TinyCat\Database\{Connection, Transactions};
use TinyCat\Core\Settings\Store;
final class SettingsCommitPDO extends PDO {
    public ?string $signal = null;
    public function commit(): bool { $ok = parent::commit(); if ($this->signal !== null) { file_put_contents($this->signal, 'committed'); } return $ok; }
}
$checks = 0; $assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$worker = null;
foreach (['filesystem', 'memcached'] as $driver) {
    if ($driver === 'memcached') {
        if (!class_exists('Memcached')) { echo "SKIP settings race Memcached: extension unavailable.\n"; continue; }
        $probe = new Memcached(); $probe->addServer('127.0.0.1', 11211);
        if (!is_string($probe->getVersion()['127.0.0.1:11211'] ?? null)) { echo "SKIP settings race Memcached: local server unavailable.\n"; continue; }
    }
    $directory = TinyCat\Tools\Workspace::directory('tests/settings-race-' . $driver . '-' . bin2hex(random_bytes(6)));
    $config = ['database' => ['driver' => 'mysql'], 'install' => ['complete' => true], 'cache' => ['directory' => $directory . '/cache', 'driver' => $driver, 'memcached' => ['prefix' => 'tinycat-settings-race-' . bin2hex(random_bytes(10)) . ':']]];
    Config::configure(new \TinyCat\Core\Runtime\Configuration($config));
    foreach (['driver' => null, 'memcachedClient' => null, 'memcachedInitialized' => false, 'memcachedConfig' => null] as $name => $value) { (new ReflectionProperty(Cache::class, $name))->setValue(null, $value); }
    $db = \tinycat_test_database_as(SettingsCommitPDO::class, $directory . '/settings.database.json'); Connection::set($db); TinyCat\Core\Settings\Schema::install($db);
    Store::reset(); Store::setSetting('site.public_enabled', true, 'bool');
    $state = $directory . '/state.json'; file_put_contents($state, json_encode(['source' => $source, 'directory' => $directory, 'config' => $config], JSON_THROW_ON_ERROR));
    $worker = proc_open([PHP_BINARY, $source . '/tests/fixtures/cache/settings-worker.php', $state], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/worker.log', 'a'], 2 => ['file', $directory . '/worker.log', 'a']], $pipes);
    if (!is_resource($worker)) { throw new RuntimeException('Cannot start settings race reader'); } fclose($pipes[0]);
    try {
        $deadline = microtime(true) + 15;
        while (!is_file($directory . '/read-ready') && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
        $assert(is_file($directory . '/read-ready'), 'Reader reached old SELECT before the writer: ' . (string) @file_get_contents($directory . '/worker.log'));
        $db->signal = $directory . '/committed';
        Transactions::run(static function (): void { Store::setSetting('site.public_enabled', false, 'bool'); });
        $deadline = microtime(true) + 15;
        while (!is_file($directory . '/read-result') && microtime(true) < $deadline && proc_get_status($worker)['running']) { usleep(10000); }
        $assert(is_file($directory . '/read-result'), 'Reader completes after the controlled commit');
        $assert(json_decode((string) file_get_contents($directory . '/read-result'), true) === true, 'Concurrent old request may retain its own snapshot');
        $assert(Cache::read('core_autoload_settings') === null, 'Completed invalidation removes the older in-flight fill');
        Store::reset(); $assert(Store::setting('site.public_enabled') === false, 'A following request cannot re-enable public web from the stale fill');
        echo "PASS controlled settings read/fill versus commit/invalidation ($driver)\n";
    } finally {
        if (is_resource($worker)) { if (proc_get_status($worker)['running']) { proc_terminate($worker); } proc_close($worker); $worker = null; }
        Cache::forget('core_autoload_settings');
    }
}
echo "PASS settings cache concurrency ($checks checks)\n";
