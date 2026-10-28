<?php
declare(strict_types=1);

namespace TinyCat\Users;

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
        $empty = [
            'posts' => 0,
            'likes_given' => 0,
            'likes_received' => 0,
            'comments' => 0,
        ];

        if ($authorId < 1) {
            return $empty;
        }

        try {
            // Drive received-like counts from this author's rows. Starting from
            // the global likes tables can make MySQL scan every like before it
            // filters by the post or comment owner.
            $receivedLikesJoin = \TinyCat\Database\Connection::driver() === 'mysql'
                ? 'STRAIGHT_JOIN'
                : 'INNER JOIN';
            $stats = \TinyCat\Database\Connection::one(
                'SELECT
                        (
                            SELECT COUNT(*)
                            FROM content
                            WHERE author_id = ?
                                AND published_at <= ?
                        ) AS posts,
                        (
                            SELECT COUNT(*)
                            FROM content_likes
                            WHERE user_id = ?
                        ) + (
                            SELECT COUNT(*)
                            FROM comment_likes
                            WHERE user_id = ?
                        ) AS likes_given,
                        (
                            SELECT COUNT(*)
                            FROM content c ' . $receivedLikesJoin . ' content_likes cl ON cl.content_id = c.id
                            WHERE c.author_id = ?
                        ) + (
                            SELECT COUNT(*)
                            FROM content_comments cc ' . $receivedLikesJoin . ' comment_likes cl ON cl.comment_id = cc.id
                            WHERE cc.user_id = ?
                        ) AS likes_received,
                        (
                            SELECT COUNT(*)
                            FROM content_comments
                            WHERE user_id = ?
                        ) AS comments',
                [$authorId, \TinyCat\Support\Dates::dateDb(), $authorId, $authorId, $authorId, $authorId, $authorId]
            ) ?? [];

            return [
                'posts' => (int) ($stats['posts'] ?? 0),
                'likes_given' => (int) ($stats['likes_given'] ?? 0),
                'likes_received' => (int) ($stats['likes_received'] ?? 0),
                'comments' => (int) ($stats['comments'] ?? 0),
            ];
        } catch (Throwable) {
            return $empty;
        }
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
            $rankedRoles = \TinyCat\Users\Roles::rolesWith('appears_in_people_rankings');
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
                    FROM content c' . $feedIndex . '
                    INNER JOIN users u ON u.id = c.author_id'
            )
                ->where('c.published_at >= ?', \TinyCat\Support\Dates::dateDb('-' . $days . ' days'))
                ->where('c.published_at <= ?', \TinyCat\Support\Dates::dateDb())
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
