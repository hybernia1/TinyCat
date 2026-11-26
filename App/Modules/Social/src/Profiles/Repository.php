<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class Repository
{
    /**
     * @return array<mixed>|null
     */
    public static function public_author_find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return \TinyCat\Database\Query::select(
            'SELECT id,
                    username,
                    username AS name,
                    role,
                    status,
                    locale,
                    theme,
                    avatar_exists,
                    avatar_version,
                    updated_at,
                    bio,
                    muted_until,
                    muted_by,
                    muted_reason,
                    created_at
                FROM users'
        )
            ->where('id = ?', $id)
            ->where('status = ?', 'active')
            ->limit(1)
            ->one();
    }

    /**
     * @return array<mixed>
     */
    public static function public_top_authors(int $limit = 5, int $days = 7, bool $compute = true): array
    {
        $limit = max(1, min(20, $limit));
        $days = max(1, min(365, $days));
        $cacheKey = 'public_top_authors_ranked_ids_v2_' . $limit . '_' . $days;

        $cached = \TinyCat\Support\Cache::get($cacheKey, 3600);

        if (is_array($cached)) {
            return self::hydrateRankedAuthors($cached);
        }

        if (!$compute) {
            $stale = \TinyCat\Support\Cache::read($cacheKey);

            return is_array($stale) ? self::hydrateRankedAuthors($stale) : [];
        }

        $ranked = \TinyCat\Support\Cache::rememberArray($cacheKey, 3600, static function () use ($limit, $days): array {
            $feedIndex = ' FORCE INDEX (content_feed_index)';
            $rankedRoles = \TinyCat\Social\Profiles\Roles::rolesWith('appears_in_people_rankings');
            $rolePlaceholders = implode(', ', array_fill(0, count($rankedRoles), '?'));
            $authors = \TinyCat\Database\Query::select(
                'SELECT u.id,
                        COUNT(*) AS posts_count,
                        MAX(c.published_at) AS latest_at
                    FROM social_posts c' . $feedIndex . '
                    INNER JOIN users u ON u.id = c.author_id'
            )

                ->where('c.published_at >= ?', \TinyCat\Support\Dates::dateDb('-' . $days . ' days'))
                ->where('c.published_at <= ? AND c.status = \'published\'', \TinyCat\Support\Dates::dateDb())
                ->where('u.status = ?', 'active')
                ->where('u.role IN (' . $rolePlaceholders . ')', ...$rankedRoles)
                ->group('u.id, u.username')
                ->order('posts_count DESC, latest_at DESC, u.username ASC')
                ->limit($limit)
                ->all();

            return $authors;
        });
        return self::hydrateRankedAuthors($ranked);
    }

    /** @param array<mixed> $ranked
     * @return list<array<mixed>> */
    private static function hydrateRankedAuthors(array $ranked): array
    {
        $ranked = array_slice($ranked, 0, 20);
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $ranked))));
        if ($ids === []) { return []; }
        $roles = Roles::rolesWith('appears_in_people_rankings');
        if ($roles === []) { return []; }
        $rows = \TinyCat\Database\Connection::all('SELECT id, username, username AS name, avatar_exists, avatar_version, updated_at, bio FROM users WHERE status = ? AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') AND role IN (' . implode(', ', array_fill(0, count($roles), '?')) . ')', ['active', ...$ids, ...$roles]);
        $current = array_column($rows, null, 'id');
        $result = [];
        foreach ($ranked as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (isset($current[$id])) { $result[] = $current[$id] + ['posts_count' => (int) ($row['posts_count'] ?? 0), 'latest_at' => (string) ($row['latest_at'] ?? '')]; }
        }
        return $result;
    }

    public static function sitemapCount(): int { return (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM users WHERE status = ?', ['active']); }
    /** @return list<array{url: string, last_modified: ?string}> */
    public static function sitemapEntries(int $limit, int $offset): array
    {
        $rows = \TinyCat\Database\Connection::all('SELECT id FROM users WHERE status = ? ORDER BY id ASC LIMIT ' . $limit . ' OFFSET ' . $offset, ['active']);
        return array_values(array_map(static fn (array $r): array => ['url' => Profiles::author_url((int) $r['id']), 'last_modified' => null], $rows));
    }
}
