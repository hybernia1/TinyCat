<?php
declare(strict_types=1);

namespace TinyCat\Social\Discussion;

final class Comments
{
    public static function visiblePredicate(string $alias = 'cc'): string
    {
        return "(SELECT visible_author.status FROM users visible_author WHERE visible_author.id = {$alias}.user_id) = 'active'"
            . " AND ({$alias}.parent_id IS NULL OR {$alias}.parent_id = 0 OR EXISTS (SELECT 1 FROM social_comments root INNER JOIN users ru ON ru.id = root.user_id WHERE root.id = {$alias}.parent_id AND root.content_id = {$alias}.content_id AND (root.parent_id IS NULL OR root.parent_id = 0) AND ru.status = 'active'))";
    }

    public static function status_comments_query(): \TinyCat\Database\Query
    {
        return \TinyCat\Database\Query::select(self::status_comments_sql())
            ->where(self::visiblePredicate());
    }

    private static function status_comments_sql(): string
    {
        return 'SELECT cc.id,
                    cc.content_id,
                    cc.parent_id,
                    cc.user_id,
                    u.id AS author_id,
                    cc.body,
                    cc.created_at,
                    u.username AS author_name,
                    u.role AS author_role,
                    u.username AS author_username,
                    u.avatar_exists AS author_avatar_exists,  u.avatar_version AS author_avatar_version,
                    u.updated_at AS author_updated_at,
                    c.author_id AS content_author_id,
                    EXISTS (SELECT 1 FROM social_comments reply INNER JOIN users reply_author ON reply_author.id = reply.user_id WHERE reply.content_id = cc.content_id AND reply.parent_id = cc.id AND reply_author.status = \'active\') AS has_replies,
                    (
                        SELECT COUNT(*)
                        FROM social_comment_likes cl
                        WHERE cl.comment_id = cc.id
                    ) AS likes_count
                FROM social_comments cc
                INNER JOIN users u ON u.id = cc.user_id
                INNER JOIN social_posts c ON c.id = cc.content_id';
    }

    /**
     * @return array<mixed>
     */
    public static function status_comments(int $contentId): array
    {
        return self::page($contentId)['items'];
    }

    /** @return array{items: array<mixed>, next_url: string} */
    public static function page(int $contentId, int $parentId = 0, string $cursorAt = '', int $cursorId = 0, ?int $limit = null): array
    {
        $limit = max(1, min(50, $limit ?? \TinyCat\Social\Posts\Feed::public_status_page_limit()));
        if ($contentId < 1) { return ['items' => [], 'next_url' => '']; }
        $params = [];

        $index = ' FORCE INDEX (content_comments_content_index)';
        $join = ' STRAIGHT_JOIN ';
        $parents = $parentId > 0 ? ['reply'] : ['cc.parent_id IS NULL', 'cc.parent_id = 0'];
        $branches = [];
        foreach ($parents as $indexNumber => $parentSql) {
            if ($parentId > 0) { $params[] = $parentId; }
            $params[] = $contentId;
            $cursorSql = '';
            if ($cursorAt !== '' && $cursorId > 0) { $cursorSql = ' AND (cc.created_at > ? OR (cc.created_at = ? AND cc.id > ?))'; array_push($params, $cursorAt, $cursorAt, $cursorId); }
            if ($parentId > 0) {
                $from = 'social_comments root' . $join . "users ru ON ru.id = root.user_id AND ru.status = 'active'" . $join . 'social_comments cc' . $index . ' ON cc.parent_id = root.id AND cc.content_id = root.content_id' . $join . "users u ON u.id = cc.user_id AND u.status = 'active'";
                $where = 'root.id = ? AND root.content_id = ? AND (root.parent_id IS NULL OR root.parent_id = 0)';
            } else {
                $from = 'social_comments cc' . $index . $join . "users u ON u.id = cc.user_id AND u.status = 'active'";
                $where = 'cc.content_id = ? AND ' . $parentSql;
            }
            $branches[] = 'SELECT * FROM (SELECT cc.id, cc.created_at FROM ' . $from . ' WHERE ' . $where . $cursorSql . ' ORDER BY cc.created_at ASC, cc.id ASC LIMIT ' . $limit . ') comment_branch_' . $indexNumber;
        }
        $selected = 'SELECT id FROM (' . implode(' UNION ALL ', $branches) . ') comment_candidates ORDER BY created_at ASC, id ASC LIMIT ' . $limit;
        $query = new \TinyCat\Database\Query(self::status_comments_sql() . ' INNER JOIN (' . $selected . ') selected_comments ON selected_comments.id = cc.id', $params);
        $rows = $query->where('cc.content_id = ?', $contentId)->where(self::visiblePredicate())->order('cc.created_at ASC, cc.id ASC')->all();
        $last = $rows === [] ? null : end($rows);
        $next = count($rows) === $limit && is_array($last)
            ? '/api/statuses/' . $contentId . '/comments?' . http_build_query(['parent_id' => $parentId, 'cursor_at' => $last['created_at'], 'cursor_id' => $last['id'], 'limit' => $limit]) : '';
        foreach ($rows as &$row) { $row['replies'] = []; }
        unset($row);
        return ['items' => $rows, 'next_url' => $next];
    }

    /**
     * @param array<int, array<string, mixed>> $comments
     */
    public static function status_preload_comment_tree_user_likes(array $comments, int $userId): void
    {
        if ($userId < 1 || $comments === []) {
            return;
        }

        \TinyCat\Social\Discussion\Comments::status_preload_comment_user_likes(
            \TinyCat\Support\Values::positive_int_ids(array_column(\TinyCat\Social\Discussion\ThreadTree::flatten($comments), 'id')),
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

        return \TinyCat\Database\Connection::find('social_comments', ['id' => $id]);
    }

    public static function status_comment_count(int $contentId): int
    {
        if ($contentId < 1) {
            return 0;
        }

        $row = \TinyCat\Database\Connection::one('SELECT COUNT(*) AS total FROM social_comments cc WHERE cc.content_id = ? AND ' . self::visiblePredicate(), [$contentId]);
        return (int) ($row['total'] ?? 0);
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

        $status = \TinyCat\Social\Posts\Repository::find((int) ($comment['content_id'] ?? 0));

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

    public static function status_comment_user_liked(int $commentId, int $userId): bool
    {
        if ($commentId < 1 || $userId < 1) {
            return false;
        }

        if (\TinyCat\Database\Connection::get()->inTransaction()) { return \TinyCat\Database\Connection::one('SELECT user_id FROM social_comment_likes WHERE comment_id = ? AND user_id = ?', [$commentId, $userId]) !== null; }

        $cache =& \TinyCat\Social\Discussion\Reactions::status_comment_user_liked_cache();

        if (!array_key_exists($commentId, $cache[$userId] ?? [])) {
            \TinyCat\Social\Discussion\Comments::status_preload_comment_user_likes([$commentId], $userId);
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

        $commentIds = \TinyCat\Support\Values::positive_int_ids($commentIds);
        $cache =& \TinyCat\Social\Discussion\Reactions::status_comment_user_liked_cache();
        $cache[$userId] ??= [];
        $missing = array_values(array_filter($commentIds, static fn (int $id): bool => !array_key_exists($id, $cache[$userId])));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $commentId) {
            $cache[$userId][$commentId] = false;
        }

        foreach (\TinyCat\Database\Query::select('SELECT comment_id FROM social_comment_likes')
            ->where('user_id = ?', $userId)
            ->whereIn('comment_id', $missing)
            ->all() as $row) {
            $commentId = (int) ($row['comment_id'] ?? 0);

            if ($commentId > 0) {
                $cache[$userId][$commentId] = true;
            }
        }
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
    public static function status_comment_item_find(int $commentId): ?array
    {
        if ($commentId < 1) {
            return null;
        }

        $comment = \TinyCat\Social\Discussion\Comments::status_comments_query()
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
            \TinyCat\Database\Connection::exec('DELETE FROM social_comment_likes WHERE comment_id = ? OR comment_id IN (SELECT id FROM social_comments WHERE parent_id = ?)', [$commentId, $commentId]);
            \TinyCat\Social\Posts\NotificationHistory::removeForCommentTree($commentId);
            \TinyCat\Database\Connection::delete('social_comments', ['parent_id' => $commentId]);
            \TinyCat\Database\Connection::delete('social_comments', ['id' => $commentId]);
        });
    }

/** @return array<mixed> */
    public static function readHistory(string $json): array
    {
        $json = trim($json);

        if ($json === '') {
            return [];
        }

        try {
            $history = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
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

    /** @param array<mixed> $actor */
    public static function appendHistory(string $before, string $body, array $actor, string $json = ''): ?string
    {

        if ($body === $before) {
            return null;
        }

        $history = self::readHistory($json);
        $history[] = [
            'at' => \TinyCat\Support\Dates::dateDb(),
            'actor' => [
                'id' => (int) ($actor['id'] ?? 0),
                'username' => \TinyCat\Core\Text\Text::plain_text_limit((string) ($actor['username'] ?? ''), 32),
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
}
