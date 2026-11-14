<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

use Throwable;

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

        return \TinyCat\Database\Queries::db_select(
            'SELECT id,
                    username,
                    username AS name,
                    role,
                    status,
                    locale,
                    theme,
                    avatar_exists,
                    avatar_appearance,
                    updated_at,
                    bio,
                    muted_until,
                    muted_by,
                    muted_reason,
                    created_at,
                    updated_at
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
    public static function author_activity_stats(int $authorId): array
    {
        return Integration::activity($authorId);
    }

    /**
     * @return array<mixed>
     */
    public static function public_top_authors(int $limit = 5, int $days = 7, bool $compute = true): array
    {
        $limit = max(1, min(20, $limit));
        $days = max(1, min(365, $days));
        $cacheKey = 'public_top_authors_ranked_' . $limit . '_' . $days;

        $cached = \TinyCat\Support\Cache::get($cacheKey, 3600);

        if (is_array($cached)) {
            return $cached;
        }

        if (!$compute) {
            $stale = \TinyCat\Support\Cache::read($cacheKey);

            return is_array($stale) ? $stale : [];
        }

        return \TinyCat\Support\Cache::rememberArray($cacheKey, 3600, static function () use ($limit, $days): array {
            $feedIndex = \TinyCat\Database\Connection::driver() === 'mysql'
                ? ' FORCE INDEX (content_feed_index)'
                : '';
            $rankedRoles = \TinyCat\Social\Profiles\Roles::rolesWith('appears_in_people_rankings');
            $rolePlaceholders = implode(', ', array_fill(0, count($rankedRoles), '?'));
            $authors = \TinyCat\Database\Queries::db_select(
                'SELECT u.id,
                        u.username,
                        u.username AS name,
                        u.avatar_exists, u.avatar_appearance,
                        u.updated_at,
                        u.bio,
                        COUNT(*) AS posts_count,
                        MAX(c.published_at) AS latest_at
                    FROM social_posts c' . $feedIndex . '
                    INNER JOIN users u ON u.id = c.author_id'
            )
                ->where('c.kind = ?', 'post')
                ->where('c.published_at >= ?', \TinyCat\Support\Dates::dateDb('-' . $days . ' days'))
                ->where('c.published_at <= ? AND c.status = \'published\'', \TinyCat\Support\Dates::dateDb())
                ->where('u.status = ?', 'active')
                ->where('u.role IN (' . $rolePlaceholders . ')', ...$rankedRoles)
                ->group('u.id, u.username, u.avatar_exists, u.avatar_appearance, u.updated_at, u.bio')
                ->order('posts_count DESC, latest_at DESC, u.username ASC')
                ->limit($limit)
                ->all();

            return $authors;
        });
    }
}
