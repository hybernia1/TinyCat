<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class Feed
{
    public static function archive_type(string $type): string
    {
        return in_array($type, ['images', 'video', 'audio', 'links', 'text'], true) ? $type : '';
    }

    private static function archive_query(\TinyCat\Database\Query $query, string $type): \TinyCat\Database\Query
    {
        $image = 'EXISTS (SELECT 1 FROM content_images fi WHERE fi.content_id = c.id)';
        $link = 'EXISTS (SELECT 1 FROM content_links fl WHERE fl.content_id = c.id)';
        return match (self::archive_type($type)) {
            'images' => $query->where($image),
            'video', 'audio' => $query->where('EXISTS (SELECT 1 FROM content_links fl INNER JOIN links l ON l.id = fl.link_id WHERE fl.content_id = c.id AND l.link_type = ?)', $type),
            'links' => $query->where($link),
            'text' => $query->where('NOT ' . $image . ' AND NOT ' . $link),
            default => $query,
        };
    }

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
                    u.avatar_exists AS author_avatar_exists, u.avatar_appearance AS author_avatar_appearance,
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

    public static function public_status_apply_visibility(\TinyCat\Database\Query $query): \TinyCat\Database\Query
    {
        return $query->where('c.published_at <= ?', \TinyCat\Support\Dates::dateDb());
    }

    public static function public_status_id_query(bool $ordered = true): \TinyCat\Database\Query
    {
        $feedIndex = $ordered && \TinyCat\Database\Connection::driver() === 'mysql'
            ? ' FORCE INDEX (content_feed_index)'
            : '';

        return self::public_status_apply_visibility(\TinyCat\Database\Queries::db_select(
            'SELECT c.id FROM content c' . $feedIndex . ' INNER JOIN users u ON u.id = c.author_id'
        )->where('u.status = ?', 'active'));
    }

    public static function public_status_author_id_query(): \TinyCat\Database\Query
    {
        $authorIndex = \TinyCat\Database\Connection::driver() === 'mysql'
            ? ' FORCE INDEX (content_author_index)'
            : '';

        return self::public_status_apply_visibility(\TinyCat\Database\Queries::db_select(
            'SELECT c.id FROM content c' . $authorIndex . ' INNER JOIN users u ON u.id = c.author_id'
        )->where('u.status = ?', 'active'));
    }

    public static function public_status_tag_id_query(int $termId): \TinyCat\Database\Query
    {
        // Let selective tags start with (term_id, content_id), without forcing a global feed scan.
        return self::public_status_apply_visibility(\TinyCat\Database\Queries::db_select(
            'SELECT c.id FROM content_tags ct INNER JOIN content c ON c.id = ct.content_id INNER JOIN users u ON u.id = c.author_id'
        )->where('ct.term_id = ?', $termId)->where('u.status = ?', 'active'));
    }

    /** @return list<int> */
    private static function recent_ids(int $limit, string $cursorAt, int $cursorId, string $type, ?int $termId = null): array
    {
        // Bound chronological work before selective filtering. A full page is
        // the global answer; an incomplete page falls back to the selective
        // query. Empty/rare filters must not force a scan of the whole feed.
        $params = [\TinyCat\Support\Dates::dateDb()];
        $enough = '';
        if ($termId !== null) {
            $enough = ' AND EXISTS (SELECT 1 FROM content_tags enough WHERE enough.term_id = ? LIMIT 1 OFFSET ' . ($limit - 1) . ')';
            $params[] = $termId;
        }
        [$cursor, $cursorParams] = self::public_status_cursor_condition($cursorAt, $cursorId);
        array_push($params, ...$cursorParams);
        $window = max(256, $limit * ($termId === null ? 32 : 16));
        $query = (new \TinyCat\Database\Query(
            'SELECT c.id FROM (SELECT c.id, c.author_id, c.published_at FROM content c FORCE INDEX (content_feed_index)'
            . ' WHERE c.published_at <= ?' . $enough . ($cursor === '' ? '' : ' AND ' . $cursor)
            . ' ORDER BY c.published_at DESC, c.id DESC LIMIT ' . $window . ') c'
            . ($termId === null ? '' : ' STRAIGHT_JOIN content_tags ct ON ct.content_id = c.id')
            . ' STRAIGHT_JOIN users u ON u.id = c.author_id',
            $params
        ))->where('u.status = ?', 'active');
        if ($termId !== null) { $query->where('ct.term_id = ?', $termId); }

        return self::public_status_ids_page(self::archive_query($query, $type), $limit);
    }

    public static function public_status_query(): \TinyCat\Database\Query
    {
        return self::public_status_apply_visibility(
            \TinyCat\Database\Queries::db_select(self::public_status_select_sql())->where('u.status = ?', 'active')
        );
    }

    /** @return list<int> */
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
        return self::public_status_items_by_ids(self::public_status_ids_page(self::public_status_apply_cursor($query, $cursorAt, $cursorId), $limit));
    }

    /** Shared c.published_at/c.id predicate for selections and derived windows.
     * @return array{0: string, 1: list<string|int>}
     */
    public static function public_status_cursor_condition(string $cursorAt = '', int $cursorId = 0): array
    {
        $cursorAt = trim($cursorAt);
        $cursorId = max(0, $cursorId);

        if ($cursorId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorAt) === 1) {
            return ['(c.published_at < ? OR (c.published_at = ? AND c.id < ?))', [$cursorAt, $cursorAt, $cursorId]];
        }
        return ['', []];
    }

    public static function public_status_apply_cursor(\TinyCat\Database\Query $query, string $cursorAt = '', int $cursorId = 0): \TinyCat\Database\Query
    {
        [$condition, $params] = self::public_status_cursor_condition($cursorAt, $cursorId);
        if ($condition !== '') { $query->where($condition, ...$params); }
        return $query;
    }

    /**
     * @return array<mixed>
     */
    /** @return array<mixed> */
    public static function public_status_items_cursor(int $limit = 24, string $cursorAt = '', int $cursorId = 0, string $type = ''): array
    {
        $type = self::archive_type($type);
        $limit = max(1, min(100, $limit));
        $media = in_array($type, ['images', 'links', 'video', 'audio'], true);
        if ($media && \TinyCat\Database\Connection::driver() === 'mysql') {
            $ids = self::recent_ids($limit, $cursorAt, $cursorId, $type);
            if (count($ids) === $limit) { return self::public_status_items_by_ids($ids); }
        }
        return self::public_status_cursor_page(self::archive_query(self::public_status_id_query(!$media), $type), $limit, $cursorAt, $cursorId);
    }

    /**
     * @return array<mixed>
     */
    /** @return array<mixed> */
    public static function public_status_items_for_user_cursor(int $userId, int $limit = 24, string $cursorAt = '', int $cursorId = 0, string $type = ''): array
    {
        if ($userId < 1) {
            return \TinyCat\Status\Feed::public_status_items_cursor($limit, $cursorAt, $cursorId, $type);
        }

        $authorIds = \TinyCat\Users\Following::public_following_author_ids($userId);

        if (count($authorIds) <= 1000) {
            return \TinyCat\Status\Feed::public_status_cursor_page(
                self::archive_query(\TinyCat\Status\Feed::public_status_author_id_query()->whereIn('c.author_id', $authorIds), $type),
                $limit,
                $cursorAt,
                $cursorId
            );
        }

        return \TinyCat\Status\Feed::public_status_cursor_page(
            self::archive_query(\TinyCat\Status\Feed::public_status_id_query(), $type)->where(
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
    public static function public_status_items_by_author_cursor(int $authorId, int $limit = 24, string $cursorAt = '', int $cursorId = 0, string $type = ''): array
    {
        if ($authorId < 1) {
            return [];
        }

        return \TinyCat\Status\Feed::public_status_cursor_page(
            self::archive_query(\TinyCat\Status\Feed::public_status_author_id_query()->where('c.author_id = ?', $authorId), $type),
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
        string $type = ''
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

        $limit = max(1, min(100, $limit));
        $type = self::archive_type($type);
        if (\TinyCat\Database\Connection::driver() === 'mysql' && ($type === '' || $type === 'text')) {
            $ids = self::recent_ids($limit, $cursorAt, $cursorId, $type, $termId);
            if (count($ids) === $limit) { return self::public_status_items_by_ids($ids); }
        }
        $query = self::public_status_tag_id_query($termId);

        return \TinyCat\Status\Feed::public_status_cursor_page(self::archive_query($query, $type), $limit, $cursorAt, $cursorId);
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
            self::public_status_tag_id_query($termId),
            $limit
        );
    }

    /** @return array<string, mixed>|null */
    public static function public_status_item(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return \TinyCat\Status\Feed::public_status_query()
            ->where('c.id = ?', $id)
            ->limit(1)
            ->one();
    }

    /**
     * @param array<mixed> $ids
     * @return list<array<string, mixed>>
     */
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
        $type = self::archive_type((string) ($params['media'] ?? ''));

        if ($context === 'author') {
            $authorId = max(0, (int) ($params['author_id'] ?? 0));

            return [
                'items' => \TinyCat\Status\Feed::public_status_items_by_author_cursor($authorId, $limit, $cursorAt, $cursorId, $type),
                'action' => \TinyCat\Users\Profiles::author_url($authorId),
            ];
        }

        if ($context === 'tag') {
            $tag = \TinyCat\Status\Tags::status_tag_normalize((string) ($params['tag'] ?? ''));

            return [
                'items' => \TinyCat\Status\Feed::public_status_items_by_tag($tag, $limit, $cursorAt, $cursorId, $type),
                'action' => \TinyCat\Status\Tags::tag_url($tag),
            ];
        }

        $feed = (string) ($params['feed'] ?? 'all') === 'following' ? 'following' : 'all';

        if ($feed === 'following') {
            $userId = (int) ($user['id'] ?? 0);

            return [
                'items' => $userId > 0 ? \TinyCat\Status\Feed::public_status_items_for_user_cursor($userId, $limit, $cursorAt, $cursorId, $type) : [],
                'action' => '/?feed=following',
            ];
        }

        return [
            'items' => \TinyCat\Status\Feed::public_status_items_cursor($limit, $cursorAt, $cursorId, $type),
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
