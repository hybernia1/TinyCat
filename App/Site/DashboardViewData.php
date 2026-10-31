<?php
declare(strict_types=1);

namespace TinyCat\Site;

use TinyCat\Support\Dates;
use TinyCat\Support\Format;
use TinyCat\Support\Localization;
use TinyCat\Support\Text;

/** Prepared dashboard presentation; diagnostics and queries remain in Dashboard. */
final class DashboardViewData
{
    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function prepare(array $data): array
    {
        $stats = [];
        foreach ($data['stats'] as $item) {
            $count = $data['counts'][$item['count_key'] ?? $item['table']] ?? null;
            $stats[] = [
                'heading' => 'h2', 'icon' => $item['icon'], 'label' => $item['label'],
                'value' => $count === null ? null : Format::number($count),
                'note' => $count === null ? Localization::t('admin.table_missing') : '',
            ];
        }
        $comments = array_map(static fn (array $comment): array => [
            'body' => trim(Text::strip_html_tags_preserving_text((string) $comment['body'])),
            'username' => '@' . $comment['username'],
            'author_url' => \TinyCat\Users\Profiles::author_url((int) $comment['user_id']),
            'content_url' => $comment['content_url'],
            'content_body' => trim(Text::strip_html_tags_preserving_text((string) $comment['content_body'])),
            'created_iso' => Dates::dateIso((string) $comment['created_at']),
            'created_label' => Dates::displayDateTime((string) $comment['created_at']),
        ], $data['recentComments']);

        return ['stats' => $stats, 'comments' => $comments, 'cache' => self::cache($data)];
    }

    /** @param array<mixed> $data
     * @return array<mixed>
     */
    private static function cache(array $data): array
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
