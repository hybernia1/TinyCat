<?php
declare(strict_types=1);
namespace TinyCat\Site;

use Throwable;

final class Dashboard
{
    /** @return array<mixed> */
    public static function data(): array
    {
        $stats = [
            ['icon' => 'users', 'label' => \TinyCat\Support\Localization::t('admin.stats.users'), 'table' => 'users'],
            ['icon' => 'file', 'label' => \TinyCat\Support\Localization::t('admin.stats.content'), 'table' => 'content'],
            ['icon' => 'clock', 'label' => \TinyCat\Support\Localization::t('admin.stats.scheduled_content'), 'table' => 'content', 'count_key' => 'scheduled_content'],
            ['icon' => 'link', 'label' => \TinyCat\Support\Localization::t('admin.stats.links'), 'table' => 'links'],
            ['icon' => 'thumb-up', 'label' => \TinyCat\Support\Localization::t('admin.stats.likes'), 'table' => 'content_likes'],
            ['icon' => 'message-circle', 'label' => \TinyCat\Support\Localization::t('admin.stats.comments'), 'table' => 'content_comments'],
            ['icon' => 'hash', 'label' => \TinyCat\Support\Localization::t('admin.stats.tags'), 'table' => 'terms'],
            ['icon' => 'user-plus', 'label' => \TinyCat\Support\Localization::t('admin.stats.follows'), 'table' => 'user_followers'],
            ['icon' => 'bell', 'label' => \TinyCat\Support\Localization::t('admin.stats.notifications'), 'table' => 'notifications'],
            ['icon' => 'flag', 'label' => \TinyCat\Support\Localization::t('admin.stats.reports'), 'table' => 'content_reports'],
        ];
        $counts = self::counts(array_map(
            static fn (array $item): string => (string) $item['table'],
            $stats
        ));
        // Publication boundaries change with time, independently of writes or the count cache.
        $counts = array_replace($counts, self::publicationCounts());
        $cache = \TinyCat\Support\Cache::diagnostics();
        $memcached = $cache['memcached'];
        $memcachedStats = $memcached['stats'];
        $opcache = $cache['opcache'];
        $opcacheStats = $opcache['stats'];
        $opcacheConfig = $opcache['configuration'];
        $recentComments = self::recentComments();
        return compact('stats', 'counts', 'cache', 'memcached', 'memcachedStats', 'opcache', 'opcacheStats', 'opcacheConfig', 'recentComments');
    }

    /** @return array{content: int|null, scheduled_content: int|null} */
    private static function publicationCounts(): array
    {
        try {
            $now = \TinyCat\Support\Dates::dateDb();
            $row = \TinyCat\Database\Connection::one(
                'SELECT COUNT(CASE WHEN published_at <= ? THEN 1 END) AS published,
                        COUNT(CASE WHEN published_at > ? THEN 1 END) AS scheduled FROM content',
                [$now, $now]
            );
            return ['content' => (int) ($row['published'] ?? 0), 'scheduled_content' => (int) ($row['scheduled'] ?? 0)];
        } catch (Throwable) {
            return ['content' => null, 'scheduled_content' => null];
        }
    }
    /** @return list<array<string, mixed>> */
    private static function recentComments(int $limit = 8): array
    {
        try {
            $rows = \TinyCat\Database\Connection::all(
                'SELECT cc.id, cc.content_id, cc.user_id, cc.body, cc.created_at, u.username, c.body AS content_body
                 FROM content_comments cc
                 INNER JOIN users u ON u.id = cc.user_id
                 INNER JOIN content c ON c.id = cc.content_id
                 ORDER BY cc.created_at DESC, cc.id DESC
                 LIMIT ' . max(1, min(30, $limit))
            );
            return array_map(static fn (array $row): array => $row + ['content_url' => \TinyCat\Status\Posts::status_url((int) ($row['content_id'] ?? 0))], array_values(array_filter($rows, is_array(...))));
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<string> $tables
     * @return array<string, int|null>
     */
    private static function counts(array $tables): array
    {
        $tables = array_values(array_unique(array_filter(array_map(
            static fn (mixed $table): string => trim((string) $table),
            $tables
        ), static fn (string $table): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1)));

        if ($tables === []) {
            return [];
        }

        $cacheKey = 'admin_dashboard_counts_managed_users_' . md5(implode('|', $tables));
        $cached = \TinyCat\Support\Cache::get($cacheKey, 300);

        if (is_array($cached)) {
            return array_map(static fn (mixed $value): ?int => $value === null ? null : (int) $value, $cached);
        }

        $counts = [];

        foreach ($tables as $table) {
            if ($table === 'content') { continue; }
            $counts[$table] = self::count($table);
        }

        \TinyCat\Support\Cache::put($cacheKey, $counts, 300);

        return $counts;
    }

    private static function count(string $table): ?int
    {
        try {
            if ($table === 'users') {
                $managedRoles = \TinyCat\Users\Roles::rolesWith('managed_by_core_admin');

                return (int) \TinyCat\Database\Connection::value(
                    'SELECT COUNT(*) FROM users WHERE role IN (' . implode(', ', array_fill(0, count($managedRoles), '?')) . ')',
                    $managedRoles
                );
            }

            return \TinyCat\Database\Connection::count($table);
        } catch (Throwable) {
            return null;
        }
    }

}
