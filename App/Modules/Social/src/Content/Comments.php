<?php
declare(strict_types=1);

namespace TinyCat\Social\Content;


final class Comments
{
    /**
     * @param array<mixed> $rows
     * @return array<mixed>
     */
    public static function status_comment_rows_to_tree(array $rows): array { return \TinyCat\Core\Ui\ThreadTree::fromRows($rows); }

    public static function status_comments_query(string $kind = 'post'): \TinyCat\Database\Query
    {
        return \TinyCat\Database\Queries::db_select(
            'SELECT cc.id,
                    cc.content_id,
                    cc.parent_id,
                    cc.user_id,
                    u.id AS author_id,
                    cc.body,
                    cc.created_at,
                    u.username AS author_name,
                    u.role AS author_role,
                    u.username AS author_username,
                    u.avatar_exists AS author_avatar_exists, u.avatar_appearance AS author_avatar_appearance,
                    u.updated_at AS author_updated_at,
                    c.author_id AS content_author_id,
                    (
                        SELECT COUNT(*)
                        FROM social_comment_likes cl
                        WHERE cl.comment_id = cc.id
                    ) AS likes_count
                FROM social_comments cc
                INNER JOIN users u ON u.id = cc.user_id
                INNER JOIN social_posts c ON c.id = cc.content_id'
        )
            ->where('c.kind = ?', $kind)->where('u.status = ?', 'active')
            ->where("(cc.parent_id IS NULL OR cc.parent_id = 0 OR EXISTS (SELECT 1 FROM social_comments root INNER JOIN users ru ON ru.id = root.user_id WHERE root.id = cc.parent_id AND root.content_id = cc.content_id AND (root.parent_id IS NULL OR root.parent_id = 0) AND ru.status = 'active'))");
    }

    /**
     * @return array<mixed>
     */
    public static function status_comments(int $contentId, string $kind = 'post'): array
    {
        if ($contentId < 1) {
            return [];
        }

        if (\TinyCat\Database\Connection::get()->inTransaction()) { return self::status_comment_rows_to_tree(self::status_comments_query($kind)->where('cc.content_id = ?', $contentId)->order('cc.created_at ASC, cc.id ASC')->all()); }

        $cache =& \TinyCat\Social\Content\ReadCache::status_comments_cache();

        if (!array_key_exists($kind . ":" . $contentId, $cache)) {
            $cache[$kind . ":" . $contentId] = \TinyCat\Social\Content\Comments::status_comment_rows_to_tree(
                \TinyCat\Social\Content\Comments::status_comments_query($kind)
                    ->where('cc.content_id = ?', $contentId)
                    ->order('cc.created_at ASC, cc.id ASC')
                    ->all()
            );
        }

        return $cache[$kind . ":" . $contentId];
    }

    /**
     * @param array<int, array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    public static function status_comment_tree_rows(array $comments): array { return \TinyCat\Core\Ui\ThreadTree::flatten($comments); }

    /**
     * @param array<int, array<string, mixed>> $comments
     */
    public static function status_preload_comment_tree_user_likes(array $comments, int $userId): void
    {
        if ($userId < 1 || $comments === []) {
            return;
        }

        \TinyCat\Social\Content\Comments::status_preload_comment_user_likes(
            \TinyCat\Support\Text::positive_int_ids(array_column(\TinyCat\Social\Content\Comments::status_comment_tree_rows($comments), 'id')),
            $userId
        );
    }

    /**
     * @return array<mixed>|null
     */
    public static function status_comment_find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return \TinyCat\Database\Queries::db_select('SELECT * FROM social_comments')
            ->where('id = ?', $id)
            ->limit(1)
            ->one();
    }

    public static function status_comment_count(int $contentId): int
    {
        if ($contentId < 1) {
            return 0;
        }

        return \TinyCat\Database\Connection::count('social_comments', ['content_id' => $contentId]);
    }

    /**
     * @param array<mixed> $comment
     * @param array<mixed> $user
     */
    public static function status_comment_can_delete(?array $comment, ?array $user): bool
    {
        if ($comment === null || $user === null) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);

        if ($userId < 1) {
            return false;
        }

        if (\TinyCat\Core\Identity\Roles::isAdmin($user) || (int) ($comment['user_id'] ?? 0) === $userId) {
            return true;
        }

        if (array_key_exists('content_author_id', $comment)) {
            return (int) ($comment['content_author_id'] ?? 0) === $userId;
        }

        $status = \TinyCat\Social\Content\Posts::find((int) ($comment['content_id'] ?? 0));

        return $status !== null && (int) ($status['author_id'] ?? 0) === $userId;
    }

    /**
     * @param array<mixed> $comment
     * @param array<mixed> $user
     */
    public static function status_comment_can_edit(?array $comment, ?array $user): bool
    {
        if ($comment === null || $user === null) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);

        return $userId > 0
            && (\TinyCat\Core\Identity\Roles::isAdmin($user) || (int) ($comment['user_id'] ?? 0) === $userId);
    }

    /**
     * @param array<mixed> $comment
     * @return array<mixed>
     */
    public static function status_comment_history(array $comment): array { return \TinyCat\Core\Text\Revisions::read((string) ($comment['comments_diff'] ?? '')); }

    /**
     * @param array<mixed> $comment
     * @param array<mixed> $actor
     */
    public static function status_comment_history_append(array $comment, string $body, array $actor): ?string { return \TinyCat\Core\Text\Revisions::append((string) ($comment['body'] ?? ''), $body, $actor, (string) ($comment['comments_diff'] ?? '')); }

    public static function status_comment_user_liked(int $commentId, int $userId): bool
    {
        if ($commentId < 1 || $userId < 1) {
            return false;
        }

        if (\TinyCat\Database\Connection::get()->inTransaction()) { return \TinyCat\Database\Connection::one('SELECT user_id FROM social_comment_likes WHERE comment_id = ? AND user_id = ?', [$commentId, $userId]) !== null; }

        $cache =& \TinyCat\Social\Content\ReadCache::status_comment_user_liked_cache();

        if (!array_key_exists($commentId, $cache[$userId] ?? [])) {
            \TinyCat\Social\Content\Comments::status_preload_comment_user_likes([$commentId], $userId);
        }

        return (bool) ($cache[$userId][$commentId] ?? false);
    }

    /**
     * @param array<mixed> $commentIds
     */
    public static function status_preload_comment_user_likes(array $commentIds, int $userId): void
    {
        if (\TinyCat\Database\Connection::get()->inTransaction()) { return; }
        if ($userId < 1) {
            return;
        }

        $commentIds = \TinyCat\Support\Text::positive_int_ids($commentIds);
        $cache =& \TinyCat\Social\Content\ReadCache::status_comment_user_liked_cache();
        $cache[$userId] ??= [];
        $missing = array_values(array_filter($commentIds, static fn (int $id): bool => !array_key_exists($id, $cache[$userId])));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $commentId) {
            $cache[$userId][$commentId] = false;
        }

        foreach (\TinyCat\Database\Queries::db_select('SELECT comment_id FROM social_comment_likes')
            ->where('user_id = ?', $userId)
            ->whereIn('comment_id', $missing)
            ->all() as $row) {
            $commentId = (int) ($row['comment_id'] ?? 0);

            if ($commentId > 0) {
                $cache[$userId][$commentId] = true;
            }
        }
    }

    public static function status_set_comment_user_liked(int $commentId, int $userId, bool $liked): void
    {
        if (\TinyCat\Database\Connection::get()->inTransaction()) { return; }
        if ($commentId < 1 || $userId < 1) {
            return;
        }

        $cache =& \TinyCat\Social\Content\ReadCache::status_comment_user_liked_cache();
        $cache[$userId][$commentId] = $liked;
    }

    public static function status_comment_like_count(int $commentId): int
    {
        if ($commentId < 1) {
            return 0;
        }

        return \TinyCat\Database\Connection::count('social_comment_likes', ['comment_id' => $commentId]);
    }

    /**
     * @return array<mixed>|null
     */
    public static function status_comment_item_find(int $commentId, string $kind = 'post'): ?array
    {
        if ($commentId < 1) {
            return null;
        }

        $comment = \TinyCat\Social\Content\Comments::status_comments_query($kind)
            ->where('cc.id = ?', $commentId)
            ->limit(1)
            ->one();

        if ($comment !== null) {
            $comment['replies'] = [];
        }

        return $comment;
    }

    public static function status_delete_comment_rows(int $commentId): void
    {
        if ($commentId < 1) {
            return;
        }

        \TinyCat\Database\Transactions::run(static function () use ($commentId): void {
            foreach (\TinyCat\Database\Queries::db_select('SELECT id FROM social_comments')->where('parent_id = ?', $commentId)->all() as $child) {
                $childId = (int) ($child['id'] ?? 0);
                \TinyCat\Database\Connection::delete('social_comment_likes', ['comment_id' => $childId]);
                Interactions::removedComment($childId);
            }

            \TinyCat\Database\Connection::delete('social_comment_likes', ['comment_id' => $commentId]);
            Interactions::removedComment($commentId);
            \TinyCat\Database\Connection::delete('social_comments', ['parent_id' => $commentId]);
            \TinyCat\Database\Connection::delete('social_comments', ['id' => $commentId]);
        });
    }

    public static function status_comment_mention(string $name): string
    {
        $handle = \TinyCat\Support\Text::slug($name);

        return $handle !== '' ? '@' . $handle . ' ' : '';
    }
}
