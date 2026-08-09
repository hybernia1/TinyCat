<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Shared cache facade for computed data and generated files.
 *
 * JSON entries use the configured persistent cache. Generated files remain
 * below storage/cache because web servers need to serve them directly.
 */
final class Cache
{
    private const int MEMCACHED_TIMEOUT_MS = 100;
    private const int MEMCACHED_MAX_ITEM_BYTES = 900000;

    private static ?object $memcachedClient = null;
    private static bool $memcachedInitialized = false;
    private static ?string $driver = null;
    private static ?array $memcachedConfig = null;

    private function __construct()
    {
    }

    public static function get(string $key, int $ttl = 300): mixed
    {
        $lookup = self::lookup($key);

        return $lookup['found'] && $lookup['written_at'] >= time() - max(1, $ttl) ? $lookup['value'] : null;
    }

    public static function read(string $key): mixed
    {
        $lookup = self::lookup($key);

        return $lookup['found'] ? $lookup['value'] : null;
    }

    public static function put(string $key, mixed $value): bool
    {
        if (self::driver() !== 'memcached') {
            return self::filesystemPut($key, $value);
        }

        if (!self::memcachedPut($key, $value)) {
            return self::filesystemPut($key, $value);
        }

        // Do not retain an older filesystem value after a successful primary
        // write. If Memcached later becomes unavailable, a cache miss is safer
        // than serving stale data from before the write.
        self::filesystemForget($key);

        return true;
    }

    public static function fresh(string $key, int $ttl = 300): bool
    {
        $lookup = self::lookup($key);

        return $lookup['found'] && $lookup['written_at'] >= time() - max(1, $ttl);
    }

    public static function forget(string $key): bool
    {
        if (self::driver() !== 'memcached') {
            return self::filesystemForget($key);
        }

        $memcached = self::memcachedForget($key);
        $filesystem = self::filesystemForget($key);

        return $memcached || $filesystem;
    }

    public static function file(string $fileName, string $namespace = ''): string
    {
        if (
            preg_match('/^[A-Za-z0-9._-]+$/', $fileName) !== 1
            || $fileName === '.'
            || $fileName === '..'
        ) {
            throw new InvalidArgumentException('Invalid cache file name.');
        }

        return self::directory($namespace) . DIRECTORY_SEPARATOR . $fileName;
    }

    public static function directory(string $namespace = ''): string
    {
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        if ($namespace === '') {
            return $directory;
        }

        foreach (explode('/', str_replace('\\', '/', $namespace)) as $segment) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $segment) !== 1) {
                throw new InvalidArgumentException('Invalid cache namespace.');
            }

            $directory .= DIRECTORY_SEPARATOR . $segment;
        }

        return $directory;
    }

    public static function writeFile(string $fileName, string $content, string $namespace = ''): bool
    {
        $directory = self::directory($namespace);
        $target = self::file($fileName, $namespace);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = tempnam($directory, '.tinycat-cache-');

        if ($temporary === false) {
            return false;
        }

        $written = file_put_contents($temporary, $content, LOCK_EX);

        if ($written === false) {
            @unlink($temporary);
            return false;
        }

        @chmod($temporary, 0664);

        if (@rename($temporary, $target)) {
            return true;
        }

        // Content-addressed files may have been created by another request.
        if (is_file($target) && @file_get_contents($target) === $content) {
            @unlink($temporary);
            return true;
        }

        // Windows cannot replace an existing file with rename(). A cache miss
        // during this very short replacement window is safe and recomputable.
        if (is_file($target) && @unlink($target) && @rename($temporary, $target)) {
            return true;
        }

        @unlink($temporary);
        return false;
    }

    /**
     * Removes files in a namespace selected by a caller-provided predicate.
     */
    public static function prune(string $namespace, callable $shouldRemove): int
    {
        $directory = self::directory($namespace);
        $entries = is_dir($directory) ? scandir($directory) : false;

        if (!is_array($entries)) {
            return 0;
        }

        $removed = 0;

        foreach ($entries as $fileName) {
            if ($fileName === '.' || $fileName === '..' || !$shouldRemove($fileName)) {
                continue;
            }

            $file = $directory . DIRECTORY_SEPARATOR . $fileName;

            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array{
     *     driver: 'filesystem'|'memcached',
     *     available: bool,
     *     memcached: array{configured: bool, extension: bool, available: bool},
     *     opcache: array{loaded: bool, enabled: bool}
     * }
     */
    public static function diagnostics(): array
    {
        $driver = self::driver();
        $memcachedConfigured = $driver === 'memcached';
        $memcachedExtension = class_exists('Memcached');
        $memcachedAvailable = $memcachedConfigured && $memcachedExtension && self::memcachedAvailable();

        return [
            'driver' => $driver,
            'available' => !$memcachedConfigured || $memcachedAvailable,
            'memcached' => [
                'configured' => $memcachedConfigured,
                'extension' => $memcachedExtension,
                'available' => $memcachedAvailable,
            ],
            'opcache' => self::opcacheDiagnostics(),
        ];
    }

    /**
     * @return array{loaded: bool, enabled: bool}
     */
    private static function opcacheDiagnostics(): array
    {
        $loaded = extension_loaded('Zend OPcache') || extension_loaded('opcache');

        if (!$loaded || !function_exists('opcache_get_status')) {
            return ['loaded' => $loaded, 'enabled' => false];
        }

        try {
            $status = @opcache_get_status(false);
        } catch (Throwable) {
            $status = false;
        }

        return [
            'loaded' => true,
            'enabled' => is_array($status) && !empty($status['opcache_enabled']),
        ];
    }

    /**
     * @return array{available: bool, found: bool, value: mixed, written_at: int}
     */
    private static function lookup(string $key): array
    {
        if (self::driver() !== 'memcached') {
            return self::filesystemLookup($key);
        }

        $lookup = self::memcachedLookup($key);

        return $lookup['available'] ? $lookup : self::filesystemLookup($key);
    }

    /**
     * @return array{available: bool, found: bool, value: mixed, written_at: int}
     */
    private static function filesystemLookup(string $key): array
    {
        $file = self::jsonFile($key);

        if (!is_file($file)) {
            return self::cacheMiss();
        }

        $json = file_get_contents($file);

        if (!is_string($json) || $json === '') {
            return self::cacheMiss();
        }

        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::cacheMiss();
        }

        clearstatcache(true, $file);
        $writtenAt = filemtime($file);

        return [
            'available' => true,
            'found' => true,
            'value' => $value,
            'written_at' => is_int($writtenAt) ? $writtenAt : 0,
        ];
    }

    private static function filesystemPut(string $key, mixed $value): bool
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return self::writeFile(self::jsonFileName($key), $json);
    }

    private static function filesystemForget(string $key): bool
    {
        $file = self::jsonFile($key);

        return !is_file($file) || @unlink($file);
    }

    /**
     * @return array{available: bool, found: bool, value: mixed, written_at: int}
     */
    private static function memcachedLookup(string $key): array
    {
        $client = self::memcachedClient();

        if ($client === null) {
            return self::cacheUnavailable();
        }

        try {
            $payload = $client->get(self::memcachedKey($key));
            $result = $client->getResultCode();
        } catch (Throwable) {
            return self::cacheUnavailable();
        }

        if ($result === \Memcached::RES_NOTFOUND) {
            return self::cacheMiss();
        }

        if ($result !== \Memcached::RES_SUCCESS || !is_string($payload)) {
            return self::cacheUnavailable();
        }

        try {
            $entry = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::cacheMiss();
        }

        if (!is_array($entry) || (int) ($entry['version'] ?? 0) !== 1 || !array_key_exists('value', $entry)) {
            return self::cacheMiss();
        }

        return [
            'available' => true,
            'found' => true,
            'value' => $entry['value'],
            'written_at' => max(0, (int) ($entry['written_at'] ?? 0)),
        ];
    }

    private static function memcachedPut(string $key, mixed $value): bool
    {
        $client = self::memcachedClient();

        if ($client === null) {
            return false;
        }

        try {
            $payload = json_encode([
                'version' => 1,
                'written_at' => time(),
                'value' => $value,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (strlen($payload) > self::memcachedMaxItemBytes()) {
            return false;
        }

        try {
            return $client->set(self::memcachedKey($key), $payload, 0);
        } catch (Throwable) {
            return false;
        }
    }

    private static function memcachedForget(string $key): bool
    {
        $client = self::memcachedClient();

        if ($client === null) {
            return false;
        }

        try {
            if ($client->delete(self::memcachedKey($key))) {
                return true;
            }

            return $client->getResultCode() === \Memcached::RES_NOTFOUND;
        } catch (Throwable) {
            return false;
        }
    }

    private static function memcachedAvailable(): bool
    {
        $client = self::memcachedClient();

        if ($client === null) {
            return false;
        }

        try {
            $versions = $client->getVersion();
        } catch (Throwable) {
            return false;
        }

        if (!is_array($versions) || $versions === []) {
            return false;
        }

        foreach ($versions as $version) {
            if (is_string($version) && $version !== '') {
                return true;
            }
        }

        return false;
    }

    private static function memcachedClient(): ?object
    {
        if (self::$memcachedInitialized) {
            return self::$memcachedClient;
        }

        self::$memcachedInitialized = true;

        if (!class_exists('Memcached')) {
            return null;
        }

        $config = self::memcachedConfig();

        try {
            $persistentId = self::persistentId($config['persistent_id'] ?? '');
            $client = $persistentId !== '' ? new \Memcached($persistentId) : new \Memcached();
            $timeout = self::boundedInt($config['timeout_ms'] ?? self::MEMCACHED_TIMEOUT_MS, 10, 1000);
            $client->setOption(\Memcached::OPT_CONNECT_TIMEOUT, $timeout);
            $client->setOption(\Memcached::OPT_SEND_TIMEOUT, $timeout * 1000);
            $client->setOption(\Memcached::OPT_RECV_TIMEOUT, $timeout * 1000);
            $client->setOption(\Memcached::OPT_RETRY_TIMEOUT, 1);
            $client->setOption(\Memcached::OPT_TCP_NODELAY, true);

            if ($client->getServerList() === [] && !$client->addServers(self::memcachedServers($config['servers'] ?? []))) {
                return null;
            }

            return self::$memcachedClient = $client;
        } catch (Throwable) {
            return null;
        }
    }

    private static function memcachedKey(string $key): string
    {
        $config = self::memcachedConfig();

        return self::memcachedPrefix((string) ($config['prefix'] ?? 'tinycat:')) . hash('sha256', $key);
    }

    private static function memcachedMaxItemBytes(): int
    {
        $config = self::memcachedConfig();

        return self::boundedInt(
            $config['max_item_bytes'] ?? self::MEMCACHED_MAX_ITEM_BYTES,
            1024,
            1000000
        );
    }

    /**
     * @return 'filesystem'|'memcached'
     */
    private static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver === 'memcached' ? 'memcached' : 'filesystem';
        }

        $config = Core::config('cache', []);
        $driver = is_array($config) ? strtolower(trim((string) ($config['driver'] ?? 'filesystem'))) : 'filesystem';

        return self::$driver = $driver === 'memcached' ? 'memcached' : 'filesystem';
    }

    private static function memcachedConfig(): array
    {
        if (self::$memcachedConfig !== null) {
            return self::$memcachedConfig;
        }

        $config = Core::config('cache.memcached', []);

        return self::$memcachedConfig = is_array($config) ? $config : [];
    }

    /**
     * @return list<array{host: string, port: int, weight: int}>
     */
    private static function memcachedServers(mixed $configured): array
    {
        $servers = [];

        foreach (is_array($configured) ? $configured : [] as $server) {
            if (!is_array($server)) {
                continue;
            }

            $host = trim((string) ($server['host'] ?? ''));
            $port = self::boundedInt($server['port'] ?? 11211, 1, 65535);

            if ($host === '' || strlen($host) > 255 || preg_match('/[\s\x00-\x1F]/', $host) === 1) {
                continue;
            }

            $servers[] = [
                'host' => $host,
                'port' => $port,
                'weight' => self::boundedInt($server['weight'] ?? 0, 0, 1000),
            ];
        }

        return $servers !== [] ? $servers : [['host' => '127.0.0.1', 'port' => 11211, 'weight' => 0]];
    }

    private static function memcachedPrefix(string $prefix): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9:_-]+/', '_', $prefix) ?? 'tinycat:';
        $prefix = substr($prefix, 0, 120);

        return $prefix !== '' ? $prefix : 'tinycat:';
    }

    private static function persistentId(mixed $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.-]+/', '_', trim((string) $value)) ?? '';

        return substr($value, 0, 120);
    }

    private static function boundedInt(mixed $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) $value));
    }

    /**
     * @return array{available: true, found: false, value: null, written_at: 0}
     */
    private static function cacheMiss(): array
    {
        return ['available' => true, 'found' => false, 'value' => null, 'written_at' => 0];
    }

    /**
     * @return array{available: false, found: false, value: null, written_at: 0}
     */
    private static function cacheUnavailable(): array
    {
        return ['available' => false, 'found' => false, 'value' => null, 'written_at' => 0];
    }

    private static function jsonFile(string $key): string
    {
        return self::file(self::jsonFileName($key));
    }

    private static function jsonFileName(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $key) ?: 'cache';

        return $safe . '.json';
    }
}
