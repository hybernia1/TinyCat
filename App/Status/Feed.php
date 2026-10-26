<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class Feed
{
    public static function public_status_select_sql(): string
    {
        return "SELECT c.id,
                    c.body,
                    c.author_id,
                    c.published_at,
                    c.edit_locked_at,
                    ci.path AS image_path,
                    ci.width AS image_width,
                    ci.height AS image_height,
                    ci.bytes AS image_bytes,
                    u.username AS author_username,
                    u.username AS author_name,
                    u.avatar_exists AS author_avatar_exists,
                    u.updated_at AS author_updated_at,
                    (
                        SELECT COUNT(*)
                        FROM content_likes cl
                        WHERE cl.content_id = c.id
                    ) AS likes_count,
                    (
                        SELECT COUNT(*)
                        FROM content_comments cc
                        WHERE cc.content_id = c.id
                    ) AS comments_count
                FROM content c
                INNER JOIN users u ON u.id = c.author_id
                LEFT JOIN content_images ci ON ci.content_id = c.id";
    }

    /**
     * @param array<string, mixed>|null $viewer
     */
    public static function public_status_apply_visibility(\TinyCat\Database\Query $query, ?array $viewer = null, bool $allowScheduled = true): \TinyCat\Database\Query
    {
        $now = \TinyCat\Support\Dates::dateDb();

        if (!$allowScheduled) {
            return $query->where('c.published_at <= ?', $now);
        }

        $viewer ??= \TinyCat\Users\Authentication::auth();

        if (\TinyCat\Users\Profiles::user_is_admin($viewer)) {
            return $query;
        }

        $viewerId = (int) ($viewer['id'] ?? 0);

        return $viewerId > 0
            ? $query->where('(c.published_at <= ? OR c.author_id = ?)', $now, $viewerId)
            : $query->where('c.published_at <= ?', $now);
    }

    /**
     * @param array<string, mixed>|null $viewer
     */
    public static function public_status_id_query(?array $viewer = null, bool $allowScheduled = true): \TinyCat\Database\Query
    {
        $feedIndex = (string) \TinyCat\Settings\Store::config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_feed_index)'
            : '';

        return \TinyCat\Status\Feed::public_status_apply_visibility(\TinyCat\Database\Queries::db_select(
            'SELECT c.id
                FROM content c' . $feedIndex . '
                INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('u.status = ?', 'active'), $viewer, $allowScheduled);
    }

    /**
     * @param array<string, mixed>|null $viewer
     */
    public static function public_status_author_id_query(?array $viewer = null, bool $allowScheduled = true): \TinyCat\Database\Query
    {
        $authorIndex = (string) \TinyCat\Settings\Store::config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_author_index)'
            : '';

        return \TinyCat\Status\Feed::public_status_apply_visibility(\TinyCat\Database\Queries::db_select(
            'SELECT c.id
                FROM content c' . $authorIndex . '
                INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('u.status = ?', 'active'), $viewer, $allowScheduled);
    }

    /**
     * @param array<string, mixed>|null $viewer
     */
    public static function public_status_query(?array $viewer = null, bool $allowScheduled = true): \TinyCat\Database\Query
    {
        return \TinyCat\Status\Feed::public_status_apply_visibility(
            \TinyCat\Database\Queries::db_select(\TinyCat\Status\Feed::public_status_select_sql())->where('u.status = ?', 'active'),
            $viewer,
            $allowScheduled
        );
    }

    /**
     * @return array<mixed>
     */
    public static function public_status_ids_page(\TinyCat\Database\Query $query, int $limit = 24): array
    {
        $limit = max(1, min(100, $limit));

        return array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $query
                ->order('c.published_at DESC, c.id DESC')
                ->limit($limit)
                ->all()
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * @return array<mixed>
     */
    public static function public_status_cursor_page(\TinyCat\Database\Query $query, int $limit = 24, string $cursorAt = '', int $cursorId = 0): array
    {
        $cursorAt = trim($cursorAt);
        $cursorId = max(0, $cursorId);

        if ($cursorId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorAt) === 1) {
            $query->where(
                '(
                        c.published_at < ?
                        OR (c.published_at = ? AND c.id < ?)
                    )',
                $cursorAt,
                $cursorAt,
                $cursorId
            );
        }

        return \TinyCat\Status\Feed::public_status_items_by_ids(\TinyCat\Status\Feed::public_status_ids_page($query, $limit));
    }

    /**
     * @return array<mixed>
     */
    /** @return array<mixed> */
    public static function public_status_items_cursor(int $limit = 24, string $cursorAt = '', int $cursorId = 0, bool $allowScheduled = true): array
    {
        return \TinyCat\Status\Feed::public_status_cursor_page(\TinyCat\Status\Feed::public_status_id_query(null, $allowScheduled), $limit, $cursorAt, $cursorId);
    }

    /**
     * @return array<mixed>
     */
    /** @return array<mixed> */
    public static function public_status_items_for_user_cursor(int $userId, int $limit = 24, string $cursorAt = '', int $cursorId = 0): array
    {
        if ($userId < 1) {
            return \TinyCat\Status\Feed::public_status_items_cursor($limit, $cursorAt, $cursorId);
        }

        $authorIds = \TinyCat\Users\Following::public_following_author_ids($userId);

        if (count($authorIds) <= 1000) {
            return \TinyCat\Status\Feed::public_status_cursor_page(
                \TinyCat\Status\Feed::public_status_author_id_query()->whereIn('c.author_id', $authorIds),
                $limit,
                $cursorAt,
                $cursorId
            );
        }

        return \TinyCat\Status\Feed::public_status_cursor_page(
            \TinyCat\Status\Feed::public_status_id_query()->where(
                '(
                        c.author_id = ?
                        OR EXISTS (
                            SELECT 1
                            FROM user_followers uf
                            WHERE uf.follower_id = ?
                                AND uf.user_id = c.author_id
                        )
                    )',
                $userId,
                $userId
            ),
            $limit,
            $cursorAt,
            $cursorId
        );
    }

    /**
     * @return array<mixed>
     */
    /** @return array<mixed> */
    public static function public_status_items_by_author_cursor(int $authorId, int $limit = 24, string $cursorAt = '', int $cursorId = 0, bool $allowScheduled = true): array
    {
        if ($authorId < 1) {
            return [];
        }

        return \TinyCat\Status\Feed::public_status_cursor_page(
            \TinyCat\Status\Feed::public_status_author_id_query(null, $allowScheduled)->where('c.author_id = ?', $authorId),
            $limit,
            $cursorAt,
            $cursorId
        );
    }

    /**
     * @return array<mixed>
     */
    public static function public_status_ids_by_author(int $authorId, int $limit = 24): array
    {
        if ($authorId < 1) {
            return [];
        }

        return \TinyCat\Status\Feed::public_status_ids_page(
            \TinyCat\Status\Feed::public_status_author_id_query()->where('c.author_id = ?', $authorId),
            $limit
        );
    }

    /**
     * @return array<mixed>
     */
    /** @return array<mixed> */
    public static function public_status_items_by_tag(
        string $tag,
        int $limit = 24,
        string $cursorAt = '',
        int $cursorId = 0,
        bool $allowScheduled = true
    ): array
    {
        $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);

        if ($tag === '') {
            return [];
        }

        $termId = \TinyCat\Status\Tags::status_term_id_exact($tag);

        if ($termId < 1) {
            return [];
        }

        $query = \TinyCat\Status\Feed::public_status_id_query(null, $allowScheduled)
            ->join('INNER JOIN content_tags ct ON ct.content_id = c.id')
            ->where('ct.term_id = ?', $termId);

        return \TinyCat\Status\Feed::public_status_cursor_page($query, $limit, $cursorAt, $cursorId);
    }

    /**
     * @return array<mixed>
     */
    public static function public_status_ids_by_tag(string $tag, int $limit = 24): array
    {
        $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);
        $termId = \TinyCat\Status\Tags::status_term_id_exact($tag);

        if ($termId < 1) {
            return [];
        }

        return \TinyCat\Status\Feed::public_status_ids_page(
            \TinyCat\Status\Feed::public_status_id_query()
                ->join('INNER JOIN content_tags ct ON ct.content_id = c.id')
                ->where('ct.term_id = ?', $termId),
            $limit
        );
    }

    /** @param array<string, mixed>|null $viewer  * @return array<mixed>|null
     */
    /** @param array<string, mixed>|null $viewer
     * @return array<string, mixed>|null */
    public static function public_status_item(int $id, ?array $viewer = null, bool $allowScheduled = true): ?array
    {
        if ($id < 1) {
            return null;
        }

        return \TinyCat\Status\Feed::public_status_query($viewer, $allowScheduled)
            ->where('c.id = ?', $id)
            ->limit(1)
            ->one();
    }

    /**
     * @param array<mixed> $ids
     * @return array<mixed>
     */
    /** @return array<mixed> */
    /** @param array<mixed> $ids
     * @return array<mixed> */
    public static function public_status_items_by_ids(array $ids, bool $preload = true): array
    {
        $ids = \TinyCat\Support\Text::positive_int_ids($ids);

        if ($ids === []) {
            return [];
        }

        $ids = array_slice($ids, 0, 100);
        $rows = \TinyCat\Status\Feed::public_status_query()
            ->whereIn('c.id', $ids)
            ->all();
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        if ($preload) {
            \TinyCat\Status\Feed::status_preload_feed($ordered);
        }

        return $ordered;
    }

    /**
     * @param array<mixed> $items
     */
    public static function status_preload_feed(array $items): void
    {
        $ids = \TinyCat\Support\Text::positive_int_ids(array_column($items, 'id'));

        if ($ids === []) {
            return;
        }

        $mentionIds = [];

        foreach ($items as $item) {
            if (!preg_match_all(\TinyCat\Status\Mentions::mention_id_pattern(), (string) ($item['body'] ?? ''), $matches)) {
                continue;
            }

            foreach ((array) ($matches[1] ?? []) as $mentionId) {
                $mentionId = (int) $mentionId;

                if ($mentionId > 0) {
                    $mentionIds[$mentionId] = $mentionId;
                }
            }
        }

        \TinyCat\Status\Mentions::author_mention_users_by_ids(array_values($mentionIds));

        $userId = (int) (\TinyCat\Users\Authentication::auth()['id'] ?? 0);

        if ($userId > 0) {
            \TinyCat\Status\Reactions::status_preload_user_likes($ids, $userId);
        }

        \TinyCat\Status\Links::status_links_cache($ids);
    }

    public static function public_status_page_limit(): int
    {
        return max(1, min(50, (int) \TinyCat\Settings\Store::config('public.status_limit', 20)));
    }

    public static function public_status_initial_page_limit(): int
    {
        return min(5, \TinyCat\Status\Feed::public_status_page_limit());
    }

    /**
     * @param array<mixed> $params
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_feed_context_items(string $context, int $limit, array $params = [], ?array $user = null): array
    {
        $limit = max(1, min(50, $limit));
        $context = in_array($context, ['home', 'author', 'tag'], true) ? $context : 'home';
        $user ??= \TinyCat\Users\Authentication::auth();
        $cursorAt = trim((string) ($params['cursor_at'] ?? ''));
        $cursorId = max(0, (int) ($params['cursor_id'] ?? 0));

        if ($context === 'author') {
            $authorId = max(0, (int) ($params['author_id'] ?? 0));

            return [
                'items' => \TinyCat\Status\Feed::public_status_items_by_author_cursor($authorId, $limit, $cursorAt, $cursorId),
                'action' => \TinyCat\Users\Profiles::author_url($authorId),
            ];
        }

        if ($context === 'tag') {
            $tag = \TinyCat\Status\Tags::status_tag_normalize((string) ($params['tag'] ?? ''));

            return [
                'items' => \TinyCat\Status\Feed::public_status_items_by_tag($tag, $limit, $cursorAt, $cursorId),
                'action' => \TinyCat\Status\Tags::tag_url($tag),
            ];
        }

        $feed = (string) ($params['feed'] ?? 'all') === 'following' ? 'following' : 'all';

        if ($feed === 'following') {
            $userId = (int) ($user['id'] ?? 0);

            return [
                'items' => $userId > 0 ? \TinyCat\Status\Feed::public_status_items_for_user_cursor($userId, $limit, $cursorAt, $cursorId) : [],
                'action' => '/?feed=following',
            ];
        }

        return [
            'items' => \TinyCat\Status\Feed::public_status_items_cursor($limit, $cursorAt, $cursorId),
            'action' => '/',
        ];
    }

    /**
     * @param array<mixed> $params
     */
    public static function status_feed_next_url(string $context, int $limit, array $params = [], bool $html = true): string
    {
        unset($params['offset'], $params['view']);
        return '/api/statuses?' . http_build_query($params + ['context' => $context, 'limit' => max(1, min(50, $limit))]);
    }

    /**
     * @param array<mixed> $items
     * @return array<mixed>
     */
    public static function status_feed_cursor_params(array $items): array
    {
        $last = $items !== [] ? end($items) : null;

        if (!is_array($last)) {
            return [];
        }

        $publishedAt = trim((string) ($last['published_at'] ?? ''));
        $id = (int) ($last['id'] ?? 0);

        if ($publishedAt === '' || $id < 1) {
            return [];
        }

        return [
            'cursor_at' => $publishedAt,
            'cursor_id' => $id,
        ];
    }

    /**
     * @param array<mixed> $params
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function status_feed_payload(string $context, int $limit, array $params = [], ?array $user = null): array
    {
        $feed = self::status_feed_context_items($context, $limit, $params, $user);
        $items = $feed['items'];
        unset($params['cursor_at'], $params['cursor_id']);
        $params += self::status_feed_cursor_params($items);
        $done = count($items) < $limit;
        return ['items' => Resource::posts($items, $user), 'count' => count($items), 'done' => $done, 'next_url' => $done ? '' : self::status_feed_next_url($context, $limit, $params)];
    }
}
