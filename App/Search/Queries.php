<?php
declare(strict_types=1);

namespace TinyCat\Search;

use TinyCat\Database\Connection;

final class Queries
{
    public const int MAX_QUERY_LENGTH = 80;

    public static function public_search_normalize_query(string $query): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    public static function length(string $query): int
    {
        return mb_strlen($query, 'UTF-8');
    }

    public static function public_search_query_too_short(string $query): bool
    {
        return self::length($query) < 2 || self::length($query) > self::MAX_QUERY_LENGTH;
    }

    /** @return array<string, mixed> */
    public static function public_search_empty_result(string $query): array
    {
        return ['query' => $query, 'tags' => [], 'users' => [], 'content' => []];
    }

    public static function public_search_excerpt(string $text, string $query, int $limit = 120): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $query = trim($query);

        if ($text === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        if ($length <= $limit) {
            return $text;
        }

        $position = $query !== ''
            ? (function_exists('mb_stripos') ? mb_stripos($text, $query, 0, 'UTF-8') : stripos($text, $query))
            : false;
        $start = $position === false ? 0 : max(0, (int) $position - 40);
        $excerpt = function_exists('mb_substr')
            ? mb_substr($text, $start, $limit, 'UTF-8')
            : substr($text, $start, $limit);

        return ($start > 0 ? '...' : '') . trim($excerpt) . ($start + $limit < $length ? '...' : '');
    }

    /**
     * @param array<mixed> $item
     * @return array<mixed>
     */
    public static function public_search_content_result(array $item, string $query, ?string $excerptText = null): array
    {
        $id = (int) ($item['id'] ?? 0);
        $authorId = (int) ($item['author_id'] ?? 0);
        $publishedAt = (string) ($item['published_at'] ?? '');
        $excerptText ??= (string) ($item['body'] ?? '');

        return [
            'id' => $id,
            'type' => 'content',
            'title' => (string) ($item['author_name'] ?? ''),
            'excerpt' => \TinyCat\Search\Queries::public_search_excerpt($excerptText, $query, 120),
            'url' => \TinyCat\Status\Posts::status_url($id),
            'author_url' => \TinyCat\Users\Profiles::author_url($authorId),
            'published_at' => $publishedAt !== '' ? \TinyCat\Support\Dates::dateIso($publishedAt) : '',
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($item),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_suggestion_tags(string $query, int $limit): array
    {
        if (strpbrk($query, '%!') !== false) { return []; }
        $tagQuery = \TinyCat\Status\Tags::status_tag_normalize($query);

        if ($tagQuery === '') {
            return [];
        }

        $tags = [];

        $rows = Connection::all("SELECT id, name FROM terms WHERE name LIKE ? ESCAPE '!' ORDER BY name ASC LIMIT " . max(1, min(12, $limit)), [Connection::escapeLike($tagQuery) . '%']);
        foreach (self::tagCounts($rows) as $tag) {
            $name = \TinyCat\Status\Tags::status_tag_normalize((string) ($tag['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tags[] = [
                'id' => (int) ($tag['id'] ?? 0),
                'type' => 'tag',
                'title' => '#' . $name,
                'excerpt' => '',
                'url' => \TinyCat\Status\Tags::tag_url($name),
                'posts_count' => (int) $tag['posts_count'],
            ];
        }

        return $tags;
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_suggestion_users(string $query, int $limit): array
    {
        $usernameQuery = \TinyCat\Users\Usernames::username_normalize(ltrim($query, '@'));

        if ($usernameQuery === '') {
            return [];
        }

        $users = [];

        foreach (\TinyCat\Database\Queries::db_select(
            'SELECT u.id, u.username, u.username AS name, u.avatar_exists, u.avatar_appearance, u.updated_at, u.bio
                FROM users u'
        )
            ->where('u.status = ?', 'active')
            ->where("u.username LIKE ? ESCAPE '!'", Connection::escapeLike($usernameQuery) . '%')
            ->order('u.username ASC, u.id ASC')
            ->limit($limit)
            ->all() as $user) {
            $id = (int) ($user['id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $users[] = [
                'id' => $id,
                'type' => 'user',
                'title' => (string) ($user['name'] ?? ''),
                'excerpt' => \TinyCat\Search\Queries::public_search_excerpt((string) ($user['bio'] ?? ''), $query, 90),
                'url' => \TinyCat\Users\Profiles::author_url($id),
                'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($user),
            ];
        }

        return $users;
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_suggestions(string $query, int $limit = 5): array
    {
        $query = \TinyCat\Search\Queries::public_search_normalize_query($query);
        $limit = max(1, min(5, $limit));

        if ($query === '' || \TinyCat\Search\Queries::public_search_query_too_short($query)) {
            return \TinyCat\Search\Queries::public_search_empty_result($query);
        }

        return [
            'query' => $query,
            'tags' => \TinyCat\Search\Queries::public_search_suggestion_tags($query, $limit),
            'users' => \TinyCat\Search\Queries::public_search_suggestion_users($query, $limit),
            'content' => [],
        ];
    }


    /**
     * Match all words in one source (post body or an attached link).
     * MySQL uses word prefixes in its existing FULLTEXT indexes; punctuation
     * and short words are literal LIKE conditions with an explicit escape.
     * @return array{0: string, 1: list<string>}
     */
    private static function textPredicate(string $query, string $columns, string $text): array
    {
        // Quotes and angle brackets delimit words, never HTML or query syntax.
        $words = preg_split('/[\s"\'<>\x{2018}\x{2019}\x{201c}\x{201d}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // A punctuation-only query still searches for its literal text.
        if ($words === []) { $words = [$query]; }
        $fulltext = [];
        $literal = [];
        $mysql = Connection::driver() === 'mysql';
        foreach ($words as $word) {
            if ($mysql && preg_match('/^[\p{L}\p{N}]{3,}$/u', $word) === 1) {
                $fulltext[] = '+' . $word . '*';
            } else {
                $literal[] = $word;
            }
        }
        $conditions = [];
        $params = [];
        if ($fulltext !== []) {
            $conditions[] = 'MATCH(' . $columns . ') AGAINST (? IN BOOLEAN MODE)';
            $params[] = implode(' ', $fulltext);
        }
        foreach ($literal as $word) {
            $conditions[] = $text . " LIKE ? ESCAPE '!'";
            $params[] = '%' . Connection::escapeLike($word) . '%';
        }
        return [implode(' AND ', $conditions), $params];
    }

    /** @return array<mixed> */
    public static function public_search_content_rows(string $query, int $limit, string $cursorAt = '', int $cursorId = 0): array
    {
        $query = self::public_search_normalize_query($query);
        if (self::length($query) < 3 || self::length($query) > self::MAX_QUERY_LENGTH) {
            return [];
        }
        $limit = max(1, min(51, $limit));
        [$body, $bodyParams] = self::textPredicate($query, 'c.body', 'c.body');
        $linkText = Connection::driver() === 'mysql'
            ? "CONCAT_WS(' ', l.normalized_url, l.title, l.description)"
            : "COALESCE(l.normalized_url, '') || ' ' || COALESCE(l.title, '') || ' ' || COALESCE(l.description, '')";
        [$link, $linkParams] = self::textPredicate($query, 'l.normalized_url, l.title, l.description', '(' . $linkText . ')');
        $now = \TinyCat\Support\Dates::dateDb();
        // Group all matching sources before pagination, including multiple links.
        $selection = new \TinyCat\Database\Query(
            'SELECT c.id, c.body, c.author_id, c.published_at, matches.link_excerpt,
                    u.username AS author_name, u.username AS author_username,
                    u.avatar_exists AS author_avatar_exists, u.avatar_appearance AS author_avatar_appearance, u.updated_at AS author_updated_at
             FROM (SELECT id, MAX(link_excerpt) AS link_excerpt FROM (
                 SELECT c.id, NULL AS link_excerpt FROM content c INNER JOIN users u ON u.id = c.author_id
                 WHERE u.status = ? AND c.published_at <= ? AND ' . $body . '
                 UNION ALL
                 SELECT c.id, ' . $linkText . ' AS link_excerpt FROM links l
                 INNER JOIN content_links cl ON cl.link_id = l.id
                 INNER JOIN content c ON c.id = cl.content_id
                 INNER JOIN users u ON u.id = c.author_id
                 WHERE u.status = ? AND c.published_at <= ? AND ' . $link . '
             ) hits GROUP BY id) matches
             INNER JOIN content c ON c.id = matches.id
             INNER JOIN users u ON u.id = c.author_id',
            ['active', $now, ...$bodyParams, 'active', $now, ...$linkParams]
        );
        return \TinyCat\Status\Feed::public_status_apply_cursor($selection, $cursorAt, $cursorId)->order('c.published_at DESC, c.id DESC')->limit($limit)->all();
    }

    /** Count exactly the posts visible on the target tag page.
     * @param array<mixed> $rows
     * @return array<mixed>
     */
    private static function tagCounts(array $rows): array
    {
        if ($rows === []) { return []; }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $selection = new \TinyCat\Database\Query(
            'SELECT ct.term_id, COUNT(*) AS posts_count FROM content_tags ct
             INNER JOIN content c ON c.id = ct.content_id
             INNER JOIN users u ON u.id = c.author_id'
        );
        $selection->whereIn('ct.term_id', $ids)->where('u.status = ?', 'active');
        $counts = [];
        foreach (\TinyCat\Status\Feed::public_status_apply_visibility($selection)->group('ct.term_id')->all() as $row) {
            $counts[(int) $row['term_id']] = (int) $row['posts_count'];
        }
        foreach ($rows as &$row) { $row['posts_count'] = $counts[(int) $row['id']] ?? 0; }
        unset($row);
        return $rows;
    }

    /** @param array<string, mixed> $cursor
     * @return array<mixed>
     */
    private static function entities(string $query, string $type, int $limit, array $cursor = []): array
    {
        $tag = $type === 'tags';
        if ($tag && strpbrk($query, '%!') !== false) { return []; }
        $needle = $tag ? \TinyCat\Status\Tags::status_tag_normalize($query) : ltrim($query, '@');
        if ($needle === '') { return []; }
        $column = $tag ? 'name' : 'username';
        $table = $tag ? 'terms' : 'users';
        $where = $tag ? '' : "status = 'active' AND ";
        $sql = 'SELECT * FROM (SELECT ' . ($tag ? 'id, name' : 'id, username, bio, avatar_exists, avatar_appearance, updated_at')
            . ', CASE WHEN ' . $column . ' = ? THEN 0 WHEN ' . $column . " LIKE ? ESCAPE '!' THEN 1 ELSE 2 END AS search_rank"
            . ' FROM ' . $table . ' WHERE ' . $where . $column . " LIKE ? ESCAPE '!') matches";
        $params = [$needle, Connection::escapeLike($needle) . '%', '%' . Connection::escapeLike($needle) . '%'];
        $selection = new \TinyCat\Database\Query($sql, $params);
        if ((int) ($cursor['cursor_id'] ?? 0) > 0 && isset($cursor['cursor_rank'], $cursor['cursor_name'])) {
            $rank = (int) $cursor['cursor_rank'];
            $name = (string) $cursor['cursor_name'];
            $selection->where('(search_rank > (? + 0) OR (search_rank = (? + 0) AND (' . $column . ' > ? OR (' . $column . ' = ? AND id > ?))))', $rank, $rank, $name, $name, (int) $cursor['cursor_id']);
        }
        $rows = $selection->order('search_rank ASC, ' . $column . ' ASC, id ASC')->limit($limit)->all();
        if ($tag) { $rows = self::tagCounts($rows); }
        return array_map(static function (array $row) use ($tag, $query): array {
            $id = (int) $row['id'];
            $item = $tag
                ? ['id' => $id, 'type' => 'tag', 'title' => '#' . $row['name'], 'url' => \TinyCat\Status\Tags::tag_url((string) $row['name']), 'posts_count' => (int) $row['posts_count']]
                : ['id' => $id, 'type' => 'user', 'title' => $row['username'], 'url' => \TinyCat\Users\Profiles::author_url($id), 'excerpt' => self::public_search_excerpt((string) ($row['bio'] ?? ''), $query, 90), 'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($row)];
            $item['cursor_rank'] = (int) $row['search_rank'];
            $item['cursor_name'] = (string) ($tag ? $row['name'] : $row['username']);
            return $item;
        }, $rows);
    }

    /** Shared page/API collection using the standard feed continuation contract.
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    public static function collection(string $query, string $type = 'content', array $cursor = [], int $limit = 0): array
    {
        $query = self::public_search_normalize_query($query);
        $type = in_array($type, ['content', 'users', 'tags'], true) ? $type : 'content';
        $limit = $limit > 0 ? min(50, $limit) : \TinyCat\Status\Feed::public_status_page_limit();
        $minimum = $type === 'content' ? 3 : 2;
        $valid = self::length($query) >= $minimum && self::length($query) <= self::MAX_QUERY_LENGTH;
        $items = [];
        $nextCursor = [];
        if ($valid) {
            if ($type === 'content') {
                $rows = self::public_search_content_rows($query, $limit + 1, (string) ($cursor['cursor_at'] ?? ''), (int) ($cursor['cursor_id'] ?? 0));
                $nextCursor = \TinyCat\Status\Feed::status_feed_cursor_params(array_slice($rows, 0, $limit));
                $items = array_map(static fn (array $row): array => self::public_search_content_result($row, $query, $row['link_excerpt'] ?? null), $rows);
            } else {
                $items = self::entities($query, $type, $limit + 1, $cursor);
                $last = $items[min(count($items), $limit) - 1] ?? null;
                if ($last !== null) { $nextCursor = ['cursor_rank' => $last['cursor_rank'], 'cursor_name' => $last['cursor_name'], 'cursor_id' => $last['id']]; }
            }
        }
        $done = count($items) <= $limit;
        $items = array_slice($items, 0, $limit);
        foreach ($items as &$item) { unset($item['cursor_rank'], $item['cursor_name']); }
        unset($item);
        $next = $done ? '' : '/api/search/results?' . http_build_query(['q' => $query, 'type' => $type, 'limit' => \TinyCat\Status\Feed::public_status_page_limit(), ...$nextCursor]);
        return ['query' => $query, 'type' => $type, 'items' => $items, 'next_url' => $next, 'done' => $done,
            'minimum_length' => $minimum, 'valid_query' => $valid];
    }
}
