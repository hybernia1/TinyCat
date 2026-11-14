<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;
use TinyCat\Support\{Components, Format, Localization, Markup};
final class Diagnostics
{
    /** @param array<mixed>|null $data */
    public static function render(?array $data = null): string
    {
        if ($data === null) {
            $cache = \TinyCat\Support\Cache::diagnostics();
            $data = ['cache' => $cache, 'memcached' => $cache['memcached'], 'memcachedStats' => $cache['memcached']['stats'], 'opcache' => $cache['opcache'], 'opcacheStats' => $cache['opcache']['stats'], 'opcacheConfig' => $cache['opcache']['configuration']];
        }
        return \TinyCat\Support\AdminViews::listCard(Localization::t('admin.cache.title'), 'database', 'dashboard-cache', '', new Markup(Components::render('ui/dashboard-cache', self::model($data))));
    }
    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function model(array $data): array
    {
        $cache = $data['cache'];
        $memcached = $data['memcached'];
        $memory = $data['memcachedStats'];
        $opcache = $data['opcache'];
        $op = $data['opcacheStats'];
        $config = $data['opcacheConfig'];
        $memcachedStatus = !$memcached['configured'] ? 'memcached_disabled'
            : ($memcached['available'] ? 'memcached_running'
                : (!$memcached['extension'] ? 'memcached_extension_missing' : 'memcached_unreachable'));
        $services = [[
            'label' => Localization::t('admin.cache.memcached_label'),
            'status' => Localization::t('admin.cache.' . $memcachedStatus),
            'details' => $memcached['available'] ? [
                Localization::t('admin.cache.servers', ['value' => Format::number($memory['servers'])]),
                Localization::t('admin.cache.version', ['value' => $memory['version'] !== '' ? $memory['version'] : '—']),
                Localization::t('admin.cache.uptime', ['value' => Format::number($memory['uptime'])]),
                Localization::t('admin.cache.items', ['value' => Format::number($memory['items'])]),
                Localization::t('admin.cache.memory', ['used' => Format::bytes($memory['bytes']), 'limit' => Format::bytes($memory['limit_bytes'])]),
                Localization::t('admin.cache.hit_rate', ['value' => Format::hitRate($memory['hits'], $memory['misses'])]),
                Localization::t('admin.cache.evictions', ['value' => Format::number($memory['evictions'])]),
            ] : [],
            'resettable' => false,
        ], [
            'label' => Localization::t('admin.cache.opcache_label'),
            'status' => Localization::t('admin.cache.' . ($opcache['enabled'] ? 'opcache_running' : 'opcache_disabled')),
            'details' => $opcache['enabled'] ? [
                Localization::t('admin.cache.cached_scripts', ['value' => Format::number($op['cached_scripts'])]),
                Localization::t('admin.cache.max_scripts', ['value' => Format::number($config['max_scripts'])]),
                Localization::t('admin.cache.memory', ['used' => Format::bytes($op['used_memory']), 'limit' => Format::bytes($config['memory_bytes'])]),
                Localization::t('admin.cache.wasted_memory', ['value' => Format::bytes($op['wasted_memory'])]),
                Localization::t('admin.cache.hit_rate', ['value' => Format::hitRate($op['hits'], $op['misses'])]),
                Localization::t('admin.cache.timestamp_checks', ['value' => Localization::t('admin.cache.' . ($config['validate_timestamps'] ? 'enabled' : 'disabled'))]),
                Localization::t('admin.cache.revalidate_frequency', ['value' => Format::number($config['revalidate_freq'])]),
                Localization::t('admin.cache.' . ($config['file_cache'] ? 'file_cache_enabled' : 'file_cache_disabled')),
            ] : [],
            'resettable' => $opcache['enabled'] && $opcache['resettable'],
        ]];
        return [
            'available' => $cache['available'],
            'driver' => Localization::t('admin.cache.' . ($cache['driver'] === 'memcached' ? 'memcached' : 'filesystem')),
            'services' => $services,
        ];
    }
}
