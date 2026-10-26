<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class Reactions
{
    public static function status_user_liked(int $contentId, int $userId): bool
    {
        if ($contentId < 1 || $userId < 1) {
            return false;
        }

        $cache =& \TinyCat\Status\ReadCache::status_user_liked_cache();

        if (!array_key_exists($contentId, $cache[$userId] ?? [])) {
            \TinyCat\Status\Reactions::status_preload_user_likes([$contentId], $userId);
        }

        return (bool) ($cache[$userId][$contentId] ?? false);
    }

    /**
     * @param array<mixed> $contentIds
     */
    public static function status_preload_user_likes(array $contentIds, int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $contentIds = \TinyCat\Support\Text::positive_int_ids($contentIds);
        $cache =& \TinyCat\Status\ReadCache::status_user_liked_cache();
        $cache[$userId] ??= [];
        $missing = array_values(array_filter($contentIds, static fn (int $id): bool => !array_key_exists($id, $cache[$userId])));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $contentId) {
            $cache[$userId][$contentId] = false;
        }

        foreach (\TinyCat\Database\Queries::db_select('SELECT content_id FROM content_likes')
            ->where('user_id = ?', $userId)
            ->whereIn('content_id', $missing)
            ->all() as $row) {
            $contentId = (int) ($row['content_id'] ?? 0);

            if ($contentId > 0) {
                $cache[$userId][$contentId] = true;
            }
        }
    }

    public static function status_set_user_liked(int $contentId, int $userId, bool $liked): void
    {
        if ($contentId < 1 || $userId < 1) {
            return;
        }

        $cache =& \TinyCat\Status\ReadCache::status_user_liked_cache();
        $cache[$userId][$contentId] = $liked;
    }

    public static function status_like_count(int $contentId): int
    {
        if ($contentId < 1) {
            return 0;
        }

        return (int) \TinyCat\Database\Connection::value(
            'SELECT COUNT(*) FROM content_likes WHERE content_id = ?',
            [$contentId]
        );
    }
}
