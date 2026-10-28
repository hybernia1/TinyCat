<?php
declare(strict_types=1);
namespace TinyCat\Health;

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
        return [
            'http' => HttpCheck::run(...),
            'database' => static fn (): string => (int) Connection::value('SELECT 1') === 1 ? 'ok' : 'critical',
            'cache' => self::cache(...),
            'storage' => static fn (): string => self::writable(Storage::path('health-probes')),
            'uploads' => static fn (): string => self::writable(Paths::basePath('uploads/.health-probes')),
            'disk' => self::disk(...),
            'runtime' => static fn (): string => extension_loaded('curl') && extension_loaded('gd') && extension_loaded('sodium') && (class_exists(\ZipArchive::class) || class_exists(\PharData::class)) ? 'ok' : 'critical',
            'scheduler' => self::scheduler(...),
            'updates' => static fn (): string => \TinyCat\Updates\Manager::maintenanceActive() ? 'critical' : 'ok',
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

    /** @return array{status: string, metrics: array<string, string>, limits: array<string, int>} */
    private static function scheduler(): array
    {
        $tasks = ['cleanup' => 4 * 3600];
        if ((int) Connection::value('SELECT COUNT(*) FROM bot_sources WHERE enabled = 1') > 0) { $tasks['feeds'] = 1800; }
        $metrics = [];
        $limits = [];
        $status = 'ok';
        foreach ($tasks as $task => $age) {
            $last = Connection::value("SELECT MAX(started_at) FROM scheduler_runs WHERE task = ? AND status IN ('completed', 'not_due', 'has_more')", [$task]);
            $latest = \TinyCat\Scheduler\Repository::latest($task);
            $reason = in_array($latest['status'] ?? '', ['failed', 'interrupted'], true)
                ? (string) $latest['status']
                : (!is_string($last) ? 'missing' : ((strtotime($last) ?: 0) < time() - $age ? 'stale' : 'ok'));
            $metrics[$task] = $reason;
            $limits[$task] = $age;
            if ($reason !== 'ok') { $status = 'warning'; }
        }
        return ['status' => $status, 'metrics' => $metrics, 'limits' => $limits];
    }
}
