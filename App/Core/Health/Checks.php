<?php
declare(strict_types=1);
namespace TinyCat\Core\Health;

use TinyCat\Database\Connection;
use TinyCat\Support\Cache;
use TinyCat\Support\Paths;
use TinyCat\Support\Storage;

/** Local diagnostics only: no user data, outbound mail or arbitrary HTTP requests. */
final class Checks
{
    /** @return array<string, callable(): (string|array{status: string, metrics: array<string, int|float|string|bool|null>, limits: array<string, int>})> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->health as $probe) { $definitions[$probe->id] = $probe->run; }
        return $definitions;
    }

    /** @return array<string, callable(): (string|array{status: string, metrics: array<string, int|float|string|bool|null>, limits: array<string, int>})> */
    public static function localDefinitions(): array
    {
        return [
            'http' => HttpCheck::run(...),
            'database' => static fn (): string => (int) Connection::value('SELECT 1') === 1 ? 'ok' : 'critical',
            'cache' => self::cache(...),
            'storage' => static fn (): string => self::writable(Storage::path('health-probes')),
            'uploads' => static fn (): string => self::writable(Paths::basePath('uploads/.health-probes')),
            'disk' => self::disk(...),
            'runtime' => static fn (): string => extension_loaded('curl') && extension_loaded('gd') && extension_loaded('sodium') && (class_exists(\ZipArchive::class) || class_exists(\PharData::class)) ? 'ok' : 'critical',
        ];
    }

    private static function cache(): string
    {
        $key = 'health-probe-' . bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));
        try {
            Cache::put($key, $value, 60, 0);
            if (Cache::get($key, 60) !== $value) { return 'critical'; }
            return Cache::diagnostics()['available'] ? 'ok' : 'warning';
        } finally { Cache::forget($key); }
    }

    private static function writable(string $directory): string
    {
        if (!Storage::ensureDirectory($directory) || is_link($directory)) { return 'critical'; }
        $path = $directory . '/.health-probe-' . bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));
        try {
            return Storage::write($path, $value) && file_get_contents($path) === $value ? 'ok' : 'critical';
        } finally {
            if (is_file($path) && !unlink($path)) { throw new \RuntimeException('Unable to remove health probe.'); }
            @rmdir($directory);
        }
    }

    private static function disk(): string
    {
        foreach ([Paths::basePath(), Storage::path(), Paths::basePath('uploads')] as $path) {
            if (!is_dir($path)) { return 'warning'; }
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            if ($free === false || $total === false || $total <= 0) { return 'warning'; }
            if ($free < 256 * 1024 * 1024 || $free / $total < 0.05) { return 'warning'; }
        }
        return 'ok';
    }

}
