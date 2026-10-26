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

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_payload(int $commentId, array $user, string $action, string $context = ''): array
    {
        $comment = \TinyCat\Status\Comments::status_comment_item_find($commentId);
        if ($comment === null) { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found'); }
        return Resource::comment($comment, $user);
    }

    public static function status_comment_input_body(): string
    {
        $body = \TinyCat\Support\Text::plain_text_limit((string) \TinyCat\Http\Request::current()->input('comment', ''), 2000);
        \TinyCat\Moderation\Blocking::moderation_require_allowed_urls($body);
        $body = \TinyCat\Status\Links::status_strip_external_urls($body);
        $body = \TinyCat\Status\Mentions::normalize_mentions_for_storage($body);
        \TinyCat\Status\Posts::status_require_valid_tags($body);

        return \TinyCat\Support\Text::plain_text_limit(\TinyCat\Status\Tags::normalize_tags_for_storage($body), 2000);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment(int $contentId, int $parentId, array $user, string $redirect = '/', string $context = ''): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $status = \TinyCat\Status\Posts::status_find($contentId);
        $body = \TinyCat\Status\Comments::status_comment_input_body();

        if ($contentId < 1 || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($status, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        if ($body === '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_required'), 422, 'comment_required', [
                'errors' => ['comment' => [\TinyCat\Support\Localization::t('account.messages.comment_required')]],
            ]);
        }

        \TinyCat\Status\Posts::status_json_require_not_muted($user);
        \TinyCat\Status\Posts::status_json_require_action($user, 'comment');

        if ($parentId > 0) {
            $parent = \TinyCat\Status\Comments::status_comment_find($parentId);

            if ($parent === null || (int) ($parent['content_id'] ?? 0) !== $contentId) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
            }

            if ((int) ($parent['parent_id'] ?? 0) > 0) {
                $parentId = (int) $parent['parent_id'];
            }
        }

        $commentId = (int) \TinyCat\Database\Connection::insert('content_comments', [
            'content_id' => $contentId,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => \TinyCat\Support\Dates::dateDb(),
        ]);
        \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'comment');
        \TinyCat\Notifications\Delivery::notifyContentOwner('content_comment', $contentId, $user, $commentId);
        \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId, $commentId, [(int) ($status['author_id'] ?? 0)]);

        $data = [
            'action' => 'comment',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'comment_id' => $commentId,
            'message' => \TinyCat\Support\Localization::t('account.messages.comment_created'),
        ];

        $data['comment'] = \TinyCat\Status\Comments::status_json_comment_payload($commentId, $user, $redirect, $context);

        return $data;
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_like(int $commentId, array $user, ?bool $desired = null): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $comment = \TinyCat\Status\Comments::status_comment_find($commentId);
        $contentId = (int) ($comment['content_id'] ?? 0);
        $status = \TinyCat\Status\Posts::status_find($contentId);

        if ($comment === null || $userId < 1 || !\TinyCat\Status\Posts::status_can_view($status, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        $liked = \TinyCat\Status\Comments::status_comment_user_liked($commentId, $userId);
        if ($desired !== null && $desired === $liked) { return ['action' => 'comment_like', 'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user), 'comment_like' => ['comment_id' => $commentId, 'likes_count' => \TinyCat\Status\Comments::status_comment_like_count($commentId), 'liked' => $liked]]; }
        if ($desired !== null) { \TinyCat\Status\Posts::status_json_require_session_interval('comment_like'); }

        if (!$liked) {
            \TinyCat\Status\Posts::status_json_require_not_muted($user);
            \TinyCat\Status\Posts::status_json_require_action($user, 'like');
        }

        if ($liked) {
            \TinyCat\Database\Connection::delete('comment_likes', ['comment_id' => $commentId, 'user_id' => $userId]);
        } else {
            \TinyCat\Database\Connection::insert('comment_likes', [
                'comment_id' => $commentId,
                'user_id' => $userId,
            ]);
            \TinyCat\Moderation\Restrictions::moderation_record_action($user, 'like');
            \TinyCat\Notifications\Delivery::notifyCommentOwner($commentId, $user);
        }

        \TinyCat\Status\Comments::status_set_comment_user_liked($commentId, $userId, !$liked);

        return [
            'action' => 'comment_like',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'comment_like' => [
                'comment_id' => $commentId,
                'likes_count' => \TinyCat\Status\Comments::status_comment_like_count($commentId),
                'liked' => !$liked,
            ],
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_update(int $commentId, array $user): array
    {
        $comment = \TinyCat\Status\Comments::status_comment_find($commentId);

        if ($comment === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        if (!\TinyCat\Status\Comments::status_comment_can_edit($comment, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_edit_forbidden'), 403, 'comment_edit_forbidden');
        }

        if (!\TinyCat\Users\Profiles::user_is_admin($user)) {
            \TinyCat\Status\Posts::status_json_require_not_muted($user);
        }
        $body = \TinyCat\Status\Comments::status_comment_input_body();

        if ($body === '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_required'), 422, 'comment_required', [
                'errors' => ['comment' => [\TinyCat\Support\Localization::t('account.messages.comment_required')]],
            ]);
        }

        $result = \TinyCat\Database\Transactions::run(static function () use ($commentId, $body, $user): array {
            $lockedComment = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM content_comments WHERE id = ?'), [$commentId]);

            if ($lockedComment === null) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
            }

            if (!\TinyCat\Status\Comments::status_comment_can_edit($lockedComment, $user)) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_edit_forbidden'), 403, 'comment_edit_forbidden');
            }

            $contentId = (int) ($lockedComment['content_id'] ?? 0);
            $status = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM content WHERE id = ?'), [$contentId]);
            $history = \TinyCat\Status\Comments::status_comment_history_append($lockedComment, $body, $user);

            if ($history === null) {
                return [
                    'changed' => false,
                    'content_id' => $contentId,
                    'status_author_id' => (int) ($status['author_id'] ?? 0),
                    'old_mention_ids' => [],
                ];
            }

            \TinyCat\Database\Connection::update('content_comments', [
                'body' => $body,
                'comments_diff' => $history,
            ], ['id' => $commentId]);

            if (\TinyCat\Users\Profiles::user_is_admin($user) && !\TinyCat\Status\Posts::status_edit_locked($status)) {
                \TinyCat\Status\Posts::status_edit_lock($contentId, $user, 'admin_comment_edit');
            }

            return [
                'changed' => true,
                'content_id' => $contentId,
                'status_author_id' => (int) ($status['author_id'] ?? 0),
                'old_mention_ids' => \TinyCat\Notifications\Delivery::mentionedUserIds((string) ($lockedComment['body'] ?? '')),
            ];
        });

        $contentId = (int) ($result['content_id'] ?? 0);

        if ((bool) ($result['changed'] ?? false)) {
            $skipUserIds = array_merge(
                (array) ($result['old_mention_ids'] ?? []),
                [(int) ($result['status_author_id'] ?? 0)]
            );
            \TinyCat\Notifications\Delivery::notifyMentions($body, $user, $contentId, $commentId, $skipUserIds);
        }

        return [
            'action' => 'comment_update',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'updated_comment' => Resource::comment(\TinyCat\Status\Comments::status_comment_item_find($commentId) ?? [], $user),

            'message' => \TinyCat\Support\Localization::t('account.messages.comment_saved'),
        ];
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_json_comment_delete(int $commentId, array $user): array
    {
        $comment = \TinyCat\Status\Comments::status_comment_find($commentId);

        if ($comment === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        $contentId = (int) ($comment['content_id'] ?? 0);

        if (!\TinyCat\Status\Comments::status_comment_can_delete($comment, $user)) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.comment_forbidden'), 403, 'comment_forbidden');
        }

        \TinyCat\Status\Comments::status_delete_comment_rows($commentId);

        return [
            'action' => 'comment_delete',
            'status' => \TinyCat\Status\Posts::status_json_summary($contentId, $user),
            'deleted_comment_id' => $commentId,
            'message' => \TinyCat\Support\Localization::t('account.messages.comment_deleted'),
        ];
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
