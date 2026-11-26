<?php
declare(strict_types=1);

namespace TinyCat\Social\Discussion;

final class Reactions
{
    public static function status_user_liked(int $contentId, int $userId): bool
    {
        if ($contentId < 1 || $userId < 1) {
            return false;
        }

        if (\TinyCat\Database\Connection::get()->inTransaction()) { return \TinyCat\Database\Connection::one('SELECT user_id FROM social_post_likes WHERE content_id = ? AND user_id = ?', [$contentId, $userId]) !== null; }

        $cache =& \TinyCat\Social\Discussion\Reactions::status_user_liked_cache();

        if (!array_key_exists($contentId, $cache[$userId] ?? [])) {
            \TinyCat\Social\Discussion\Reactions::status_preload_user_likes([$contentId], $userId);
        }

        return (bool) ($cache[$userId][$contentId] ?? false);
    }

    /**
     * @param array<mixed> $contentIds
     */
    public static function status_preload_user_likes(array $contentIds, int $userId): void
    {
        if (\TinyCat\Database\Connection::get()->inTransaction()) { return; }
        if ($userId < 1) {
            return;
        }

        $contentIds = \TinyCat\Support\Values::positive_int_ids($contentIds);
        $cache =& \TinyCat\Social\Discussion\Reactions::status_user_liked_cache();
        $cache[$userId] ??= [];
        $missing = array_values(array_filter($contentIds, static fn (int $id): bool => !array_key_exists($id, $cache[$userId])));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $contentId) {
            $cache[$userId][$contentId] = false;
        }

        foreach (\TinyCat\Database\Query::select('SELECT content_id FROM social_post_likes')
            ->where('user_id = ?', $userId)
            ->whereIn('content_id', $missing)
            ->all() as $row) {
            $contentId = (int) ($row['content_id'] ?? 0);

            if ($contentId > 0) {
                $cache[$userId][$contentId] = true;
            }
        }
    }

    public static function status_like_count(int $contentId): int
    {
        if ($contentId < 1) {
            return 0;
        }

        return (int) \TinyCat\Database\Connection::value(
            'SELECT COUNT(*) FROM social_post_likes WHERE content_id = ?',
            [$contentId]
        );
    }

    /**
     * @return array<mixed>
     */
    public static function &status_user_liked_cache(): array
    {
        static $cache = [];

        return $cache;
    }

    /**
     * @return array<mixed>
     */
    public static function &status_comment_user_liked_cache(): array
    {
        static $cache = [];

        return $cache;
    }
}
