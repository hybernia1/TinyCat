<?php
declare(strict_types=1);

namespace TinyCat\Status;

use JsonException;

final class Comments
{
    /**
     * @param array<mixed> $rows
     * @return array<mixed>
     */
    public static function status_comment_rows_to_tree(array $rows): array
    {
        $parents = [];
        $children = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parentId = (int) ($row['parent_id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $row['replies'] = [];

            if ($parentId > 0) {
                $children[$parentId][] = $row;
                continue;
            }

            $parents[$id] = $row;
        }

        foreach ($children as $parentId => $items) {
            if (!isset($parents[$parentId])) {
                continue;
            }

            $parents[$parentId]['replies'] = $items;
        }

        return array_values($parents);
    }

    public static function status_comments_query(): \TinyCat\Database\Query
    {
        return \TinyCat\Database\Queries::db_select(
            'SELECT cc.id,
                    cc.content_id,
                    cc.parent_id,
                    cc.user_id,
                    cc.body,
                    cc.created_at,
                    u.username AS author_name,
                    u.username AS author_username,
                    u.avatar_exists AS author_avatar_exists,
                    u.updated_at AS author_updated_at,
                    c.author_id AS content_author_id,
                    (
                        SELECT COUNT(*)
                        FROM comment_likes cl
                        WHERE cl.comment_id = cc.id
                    ) AS likes_count
                FROM content_comments cc
                INNER JOIN users u ON u.id = cc.user_id
                INNER JOIN content c ON c.id = cc.content_id'
        )
            ->where('u.status = ?', 'active');
    }

    /**
     * @return array<mixed>
     */
    public static function status_comments(int $contentId): array
    {
        if ($contentId < 1) {
            return [];
        }

        $cache =& \TinyCat\Status\ReadCache::status_comments_cache();

        if (!array_key_exists($contentId, $cache)) {
            $cache[$contentId] = \TinyCat\Status\Comments::status_comment_rows_to_tree(
                \TinyCat\Status\Comments::status_comments_query()
                    ->where('cc.content_id = ?', $contentId)
                    ->order('cc.created_at ASC, cc.id ASC')
                    ->all()
            );
        }

        return $cache[$contentId];
    }

    /**
     * @param array<int, array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    public static function status_comment_tree_rows(array $comments): array
    {
        $rows = [];
        $pending = $comments;

        while ($pending !== []) {
            $comment = array_pop($pending);

            $rows[] = $comment;

            foreach ((array) ($comment['replies'] ?? []) as $reply) {
                if (is_array($reply)) {
                    $pending[] = $reply;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $comments
     */
    public static function status_preload_comment_tree_user_likes(array $comments, int $userId): void
    {
        if ($userId < 1 || $comments === []) {
            return;
        }

        \TinyCat\Status\Comments::status_preload_comment_user_likes(
            \TinyCat\Support\Text::positive_int_ids(array_column(\TinyCat\Status\Comments::status_comment_tree_rows($comments), 'id')),
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

        return \TinyCat\Database\Queries::db_select('SELECT * FROM content_comments')
            ->where('id = ?', $id)
            ->limit(1)
            ->one();
    }

    public static function status_comment_count(int $contentId): int
    {
        if ($contentId < 1) {
            return 0;
        }

        return \TinyCat\Database\Connection::count('content_comments', ['content_id' => $contentId]);
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

        if (\TinyCat\Users\Profiles::user_is_admin($user) || (int) ($comment['user_id'] ?? 0) === $userId) {
            return true;
        }

        if (array_key_exists('content_author_id', $comment)) {
            return (int) ($comment['content_author_id'] ?? 0) === $userId;
        }

        $status = \TinyCat\Status\Posts::status_find((int) ($comment['content_id'] ?? 0));

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
            && (\TinyCat\Users\Profiles::user_is_admin($user) || (int) ($comment['user_id'] ?? 0) === $userId);
    }

    /**
     * @param array<mixed> $comment
     * @return array<mixed>
     */
    public static function status_comment_history(array $comment): array
    {
        $json = trim((string) ($comment['comments_diff'] ?? ''));

        if ($json === '') {
            return [];
        }

        try {
            $history = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, static function (mixed $entry): bool {
            return is_array($entry)
                && is_array($entry['changes'] ?? null)
                && is_array($entry['changes']['body'] ?? null);
        }));
    }

    /**
     * @param array<mixed> $comment
     * @param array<mixed> $actor
     */
    public static function status_comment_history_append(array $comment, string $body, array $actor): ?string
    {
        $before = (string) ($comment['body'] ?? '');

        if ($body === $before) {
            return null;
        }

        $history = \TinyCat\Status\Comments::status_comment_history($comment);
        $history[] = [
            'at' => \TinyCat\Support\Dates::dateDb(),
            'actor' => [
                'id' => (int) ($actor['id'] ?? 0),
                'username' => \TinyCat\Support\Text::plain_text_limit((string) ($actor['username'] ?? ''), 32),
                'role' => (string) ($actor['role'] ?? ''),
            ],
            'changes' => [
                'body' => [
                    'from' => $before,
                    'to' => $body,
                ],
            ],
        ];

        return json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function status_comment_user_liked(int $commentId, int $userId): bool
    {
        if ($commentId < 1 || $userId < 1) {
            return false;
        }

        $cache =& \TinyCat\Status\ReadCache::status_comment_user_liked_cache();

        if (!array_key_exists($commentId, $cache[$userId] ?? [])) {
            \TinyCat\Status\Comments::status_preload_comment_user_likes([$commentId], $userId);
        }

        return (bool) ($cache[$userId][$commentId] ?? false);
    }

    /**
     * @param array<mixed> $commentIds
     */
    public static function status_preload_comment_user_likes(array $commentIds, int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $commentIds = \TinyCat\Support\Text::positive_int_ids($commentIds);
        $cache =& \TinyCat\Status\ReadCache::status_comment_user_liked_cache();
        $cache[$userId] ??= [];
        $missing = array_values(array_filter($commentIds, static fn (int $id): bool => !array_key_exists($id, $cache[$userId])));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $commentId) {
            $cache[$userId][$commentId] = false;
        }

        foreach (\TinyCat\Database\Queries::db_select('SELECT comment_id FROM comment_likes')
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
        if ($commentId < 1 || $userId < 1) {
            return;
        }

        $cache =& \TinyCat\Status\ReadCache::status_comment_user_liked_cache();
        $cache[$userId][$commentId] = $liked;
    }

    public static function status_comment_like_count(int $commentId): int
    {
        if ($commentId < 1) {
            return 0;
        }

        return \TinyCat\Database\Connection::count('comment_likes', ['comment_id' => $commentId]);
    }

    /**
     * @return array<mixed>|null
     */
    public static function status_comment_item_find(int $commentId): ?array
    {
        if ($commentId < 1) {
            return null;
        }

        $comment = \TinyCat\Status\Comments::status_comments_query()
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
            foreach (\TinyCat\Database\Queries::db_select('SELECT id FROM content_comments')->where('parent_id = ?', $commentId)->all() as $child) {
                $childId = (int) ($child['id'] ?? 0);
                \TinyCat\Database\Connection::delete('comment_likes', ['comment_id' => $childId]);
                \TinyCat\Notifications\Inbox::removeForComment($childId);
            }

            \TinyCat\Database\Connection::delete('comment_likes', ['comment_id' => $commentId]);
            \TinyCat\Notifications\Inbox::removeForComment($commentId);
            \TinyCat\Database\Connection::delete('content_comments', ['parent_id' => $commentId]);
            \TinyCat\Database\Connection::delete('content_comments', ['id' => $commentId]);
        });
    }

    public static function status_comment_mention(string $name): string
    {
        $handle = \TinyCat\Support\Text::slug($name);

        return $handle !== '' ? '@' . $handle . ' ' : '';
    }
}
