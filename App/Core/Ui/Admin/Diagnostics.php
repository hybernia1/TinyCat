<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;
use TinyCat\Core\Ui\Components;
use TinyCat\Support\Localization;
final class Diagnostics
{
    /** @param array<mixed>|null $data */
    public static function render(?array $data = null): string
    {
        if ($data === null) {
            $cache = \TinyCat\Support\Cache::diagnostics();
            $opcache = \TinyCat\Core\Runtime\Debug::opcacheDiagnostics();
            $data = ['cache' => $cache, 'memcached' => $cache['memcached'], 'memcachedStats' => $cache['memcached']['stats'], 'opcache' => $opcache, 'opcacheStats' => $opcache['stats'], 'opcacheConfig' => $opcache['configuration']];
        }
        return Components::render('ui/cache-services', self::model($data));
    }
    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function model(array $data): array
    {
        $cache = $data['cache']; $memcached = $data['memcached']; $memory = $data['memcachedStats'];
        $opcache = $data['opcache']; $op = $data['opcacheStats']; $config = $data['opcacheConfig'];
        $row = static fn (string $key, string $value): array => ['label' => Localization::t('admin.cache.' . $key), 'value' => $value];
        $toggle = static fn (string $key, bool $enabled): array => $row($key, Localization::t('admin.cache.' . ($enabled ? 'enabled' : 'disabled'))) + ['badge' => true, 'tone' => $enabled ? 'success' : 'info'];
        $memcachedStatus = !$memcached['configured'] ? 'memcached_disabled' : ($memcached['available'] ? 'memcached_running' : (!$memcached['extension'] ? 'memcached_extension_missing' : 'memcached_unreachable'));
        $memcachedTone = !$memcached['configured'] ? 'info' : ($memcached['available'] ? 'success' : 'danger');
        $memoryRows = $memcached['available'] ? [
            $row('servers', self::number($memory['servers'])),
            $row('version', $memory['version'] !== '' ? $memory['version'] : "\u{2014}"),
            $row('uptime', self::number($memory['uptime']) . ' s'),
            $row('items', self::number($memory['items'])),
            $row('memory', self::bytes($memory['bytes']) . ' / ' . self::bytes($memory['limit_bytes'])),
            $row('hit_rate', self::hitRate($memory['hits'], $memory['misses'])),
            $row('evictions', self::number($memory['evictions'])),
        ] : [];
        $opcodeRows = $opcache['enabled'] ? [
            $row('cached_scripts', self::number($op['cached_scripts'])),
            $row('max_scripts', self::number($config['max_scripts'])),
            $row('memory', self::bytes($op['used_memory']) . ' / ' . self::bytes($config['memory_bytes'])),
            $row('wasted_memory', self::bytes($op['wasted_memory'])),
            $row('hit_rate', self::hitRate($op['hits'], $op['misses'])),
            $toggle('timestamp_checks', $config['validate_timestamps']),
            $row('revalidate_frequency', self::number($config['revalidate_freq']) . ' s'),
            $toggle('file_cache', $config['file_cache']),
        ] : [];
        return ['services' => [
            ['icon' => 'database', 'label' => Localization::t('admin.cache.memcached_label'), 'status' => Localization::t('admin.cache.' . $memcachedStatus),
                'badge_label' => Localization::t('admin.cache.' . (!$memcached['configured'] ? 'disabled' : ($memcached['available'] ? 'enabled' : 'fallback'))), 'tone' => $memcachedTone, 'issue' => $memcachedTone === 'danger', 'rows' => $memoryRows, 'resettable' => false],
            ['icon' => 'code', 'label' => Localization::t('admin.cache.opcache_label'), 'status' => Localization::t('admin.cache.' . ($opcache['enabled'] ? 'opcache_running' : 'opcache_disabled')),
                'badge_label' => Localization::t('admin.cache.' . ($opcache['enabled'] ? 'enabled' : 'disabled')), 'tone' => $opcache['enabled'] ? 'success' : 'info', 'rows' => $opcodeRows, 'resettable' => $opcache['enabled'] && $opcache['resettable']],
        ]];
    }
    private static function number(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    private static function bytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 1, '.', ' ') . ' ' . $units[$unit];
    }

    private static function hitRate(int $hits, int $misses): string
    {
        $total = max(0, $hits) + max(0, $misses);

        return $total === 0 ? '—' : number_format((max(0, $hits) / $total) * 100, 1, '.', ' ') . '%';
    }
}
