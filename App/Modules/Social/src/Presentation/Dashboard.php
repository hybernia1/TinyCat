<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

use Throwable;

final class Dashboard
{
    /** @return array<mixed> */
    public static function data(): array
    {
        $stats = [
            ['icon' => 'file', 'label' => \TinyCat\Support\Localization::t('admin.stats.content'), 'table' => 'social_posts'],
            ['icon' => 'clock', 'label' => \TinyCat\Support\Localization::t('admin.stats.scheduled_content'), 'table' => 'social_posts', 'count_key' => 'scheduled_content'],
            ['icon' => 'thumb-up', 'label' => \TinyCat\Support\Localization::t('admin.stats.likes'), 'table' => 'social_post_likes'],
            ['icon' => 'message-circle', 'label' => \TinyCat\Support\Localization::t('admin.stats.comments'), 'table' => 'social_comments'],
            ['icon' => 'user-plus', 'label' => \TinyCat\Support\Localization::t('admin.stats.follows'), 'table' => 'user_followers'],
            ['icon' => 'flag', 'label' => \TinyCat\Support\Localization::t('admin.stats.reports'), 'table' => 'content_reports'],
        ];
        $counts = self::counts(array_map(
            static fn (array $item): string => (string) $item['table'],
            $stats
        ));
        // Publication boundaries change with time, independently of writes or the count cache.
        $counts = array_replace($counts, self::publicationCounts());
        $recentComments = self::recentComments();
        return compact('stats', 'counts', 'recentComments');
    }

    /** @return array{social_posts: int|null, scheduled_content: int|null} */
    private static function publicationCounts(): array
    {
        try {
            $now = \TinyCat\Support\Dates::dateDb();
            $row = \TinyCat\Database\Connection::one(
                'SELECT COUNT(CASE WHEN published_at <= ? THEN 1 END) AS published,
                        COUNT(CASE WHEN published_at > ? THEN 1 END) AS scheduled FROM social_posts WHERE kind = \'post\'',
                [$now, $now]
            );
            return ['social_posts' => (int) ($row['published'] ?? 0), 'scheduled_content' => (int) ($row['scheduled'] ?? 0)];
        } catch (Throwable) {
            return ['social_posts' => null, 'scheduled_content' => null];
        }
    }
    /** @return list<array<string, mixed>> */
    private static function recentComments(int $limit = 8): array
    {
        try {
            $rows = \TinyCat\Database\Connection::all(
                'SELECT cc.id, cc.content_id, cc.user_id, cc.body, cc.created_at, u.username, c.body AS content_body
                 FROM social_comments cc
                 INNER JOIN users u ON u.id = cc.user_id
                 INNER JOIN social_posts c ON c.id = cc.content_id
                 WHERE c.kind = \'post\'
                 ORDER BY cc.created_at DESC, cc.id DESC
                 LIMIT ' . max(1, min(30, $limit))
            );
            return array_map(static fn (array $row): array => $row + ['content_url' => \TinyCat\Social\Posts\Posts::status_url((int) ($row['content_id'] ?? 0))], array_values(array_filter($rows, is_array(...))));
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

        $cacheKey = 'admin_dashboard_counts_social_v2_' . md5(implode('|', $tables));
        $cached = \TinyCat\Support\Cache::get($cacheKey, 300);

        if (is_array($cached)) {
            return array_map(static fn (mixed $value): ?int => $value === null ? null : (int) $value, $cached);
        }

        $counts = [];

        foreach ($tables as $table) {
            if ($table === 'social_posts') { continue; }
            $counts[$table] = self::count($table);
        }

        \TinyCat\Support\Cache::put($cacheKey, $counts, 300);

        return $counts;
    }

    private static function count(string $table): ?int
    {
        try {
            if (in_array($table, ['social_comments', 'social_post_likes'], true)) { return (int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM " . $table . " i INNER JOIN social_posts c ON c.id = i.content_id WHERE c.kind = 'post'"); }
            if ($table === 'users') {
                $managedRoles = \TinyCat\Social\Profiles\Roles::rolesWith('managed_by_core_admin');

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
