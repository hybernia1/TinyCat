<?php
declare(strict_types=1);

namespace TinyCat\Users;

final class Following
{
    public static function author_is_followed(int $followerId, int $authorId): bool
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return false;
        }

        return (int) \TinyCat\Database\Connection::value(
            'SELECT COUNT(*)
                FROM user_followers
                WHERE user_id = ?
                    AND follower_id = ?',
            [$authorId, $followerId]
        ) > 0;
    }

    public static function author_follow(int $followerId, int $authorId): void
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return;
        }

        if (\TinyCat\Users\Following::author_is_followed($followerId, $authorId)) {
            return;
        }

        try {
            \TinyCat\Database\Connection::insert('user_followers', ['user_id' => $authorId, 'follower_id' => $followerId]);
        } catch (\PDOException $exception) {
            if (!\TinyCat\Database\Connection::uniqueViolation($exception)) { throw $exception; }
            return;
        }
        \TinyCat\Notifications\Delivery::notifyFollowedUser($authorId, ['id' => $followerId]);
    }

    /**
     * @return array<mixed>
     */
    public static function author_follow_counts(int $authorId): array
    {
        if ($authorId < 1) {
            return ['followers' => 0, 'following' => 0];
        }

        $counts = \TinyCat\Database\Connection::one(
            'SELECT
                    (
                        SELECT COUNT(*)
                        FROM user_followers
                        WHERE user_id = ?
                    ) AS followers,
                    (
                        SELECT COUNT(*)
                        FROM user_followers
                        WHERE follower_id = ?
                    ) AS following',
            [$authorId, $authorId]
        ) ?? [];

        return [
            'followers' => (int) ($counts['followers'] ?? 0),
            'following' => (int) ($counts['following'] ?? 0),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function author_following_profiles(int $authorId, int $limit = 12, string $cursorAt = '', int $cursorId = 0): array
    {
        if ($authorId < 1) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $cursorAt = trim($cursorAt);
        $cursorId = max(0, $cursorId);
        $params = [$authorId, 'active'];
        $cursorSql = '';

        if ($cursorId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorAt) === 1) {
            $cursorSql = '
                    AND (
                        uf.created_at < ?
                        OR (uf.created_at = ? AND uf.user_id > ?)
                    )';
            array_push($params, $cursorAt, $cursorAt, $cursorId);
        }

        return \TinyCat\Database\Connection::all(
            'SELECT u.id,
                    u.username,
                    u.username AS name,
                    u.avatar_exists, u.avatar_appearance,
                    u.updated_at,
                    uf.created_at AS followed_at
                FROM user_followers uf
                INNER JOIN users u ON u.id = uf.user_id
                WHERE uf.follower_id = ?
                    AND u.status = ?' . $cursorSql . '
                ORDER BY uf.created_at DESC, uf.user_id ASC
                LIMIT ' . $limit,
            $params
        );
    }

    /**
     * @param array<mixed> $profiles
     * @return array<mixed>
     */
    public static function author_following_cursor_params(array $profiles): array
    {
        $last = $profiles !== [] ? end($profiles) : null;

        if (!is_array($last)) {
            return [];
        }

        $followedAt = trim((string) ($last['followed_at'] ?? ''));
        $id = max(0, (int) ($last['id'] ?? 0));

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $followedAt) === 1 && $id > 0
            ? ['cursor_at' => $followedAt, 'cursor_id' => $id]
            : [];
    }

    /**
     * @param array<mixed> $profile
     * @return array<mixed>
     */
    public static function author_following_profile_payload(array $profile): array
    {
        $id = (int) ($profile['id'] ?? 0);

        return [
            'id' => $id,
            'username' => (string) ($profile['username'] ?? ''),
            'name' => \TinyCat\Users\Profiles::user_display_name($profile),
            'url' => \TinyCat\Users\Profiles::author_url($id),
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($profile),
            'followed_at' => (string) ($profile['followed_at'] ?? ''),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function public_following_author_ids(int $userId): array
    {
        static $cache = [];

        if ($userId < 1) {
            return [];
        }

        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }

        $ids = array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            \TinyCat\Database\Connection::all('SELECT user_id FROM user_followers WHERE follower_id = ?', [$userId])
        );
        $ids[] = $userId;
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        return $cache[$userId] = $ids;
    }

    public static function author_following_modal_id(int $authorId): string
    {
        return 'following-modal-' . max(0, $authorId);
    }

    public static function author_following_modal_url(int $authorId, string $cursorAt = '', int $cursorId = 0): string
    {
        return \TinyCat\Users\Following::author_following_api_url($authorId, $cursorAt, $cursorId, true);
    }

    public static function author_following_api_url(int $authorId, string $cursorAt = '', int $cursorId = 0, bool $html = false): string
    {
        return '/api/users/' . $authorId . '/following?' . http_build_query(['cursor_at' => $cursorAt, 'cursor_id' => $cursorId]);
    }
}
