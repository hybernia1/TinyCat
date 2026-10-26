<?php
declare(strict_types=1);

namespace TinyCat\Search;

use Throwable;

final class Queries
{
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
     * @return array<mixed>
     */
    public static function public_search_empty_result(string $query): array
    {
        return [
            'query' => $query,
            'tags' => [],
            'users' => [],
            'content' => [],
        ];
    }

    public static function public_search_normalize_query(string $query): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    public static function public_search_query_too_short(string $query): bool
    {
        return (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) < 2;
    }

    public static function public_search_fulltext_query(string $query): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);

            if ($length < 3) {
                continue;
            }

            $terms[] = '+' . $token . '*';

            if (count($terms) >= 6) {
                break;
            }
        }

        return implode(' ', $terms);
    }

    public static function public_search_fulltext_ready(string $table, string $index): bool
    {
        static $cache = [];

        $key = $table . ':' . $index;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if ((string) \TinyCat\Settings\Store::config('database.driver', 'mysql') !== 'mysql') {
            $cache[$key] = false;
            return false;
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $index)) {
            $cache[$key] = false;
            return false;
        }

        if ((bool) \TinyCat\Settings\Store::config('install.complete', false)) {
            return $cache[$key] = true;
        }

        try {
            $cache[$key] = (int) \TinyCat\Database\Connection::value(
                'SELECT COUNT(*)
                    FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND INDEX_NAME = ?
                        AND INDEX_TYPE = ?',
                [$table, $index, 'FULLTEXT']
            ) > 0;
        } catch (Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    public static function public_search_text_contains(string $text, string $query): bool
    {
        if ($text === '' || $query === '') {
            return false;
        }

        return (function_exists('mb_stripos') ? mb_stripos($text, $query, 0, 'UTF-8') : stripos($text, $query)) !== false;
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
            'published_at' => $publishedAt,
            'published_label' => \TinyCat\Support\Dates::displayDateTime($publishedAt),
            'avatar_url' => \TinyCat\Users\Avatars::user_avatar_url($item),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_recent_content_scan(string $query, int $limit): array
    {
        $feedIndex = (string) \TinyCat\Settings\Store::config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_feed_index)'
            : '';
        $scanLimit = max(300, min(5000, $limit * 250));
        $limit = max(1, min(50, $limit));

        return \TinyCat\Database\Connection::all(
            'SELECT c.id,
                    c.body,
                    c.author_id,
                    c.published_at,
                    u.username AS author_name,
                    u.username AS author_username,
                    u.avatar_exists AS author_avatar_exists,
                    u.updated_at AS author_updated_at
                FROM (
                    SELECT id, published_at
                    FROM content' . $feedIndex . '
                    WHERE published_at <= ?
                    ORDER BY published_at DESC, id DESC
                    LIMIT ' . $scanLimit . '
                ) recent
                INNER JOIN content c ON c.id = recent.id
                INNER JOIN users u ON u.id = c.author_id
                WHERE u.status = ?
                    AND c.body LIKE ?
                ORDER BY recent.published_at DESC, recent.id DESC
                LIMIT ' . $limit,
            [\TinyCat\Support\Dates::dateDb(), 'active', '%' . $query . '%']
        );
    }

    /**
     * @param array<mixed> $excludeIds
     * @return array<mixed>
     */
    public static function public_search_link_content_rows(string $query, int $limit, array $excludeIds = []): array
    {
        $limit = max(1, min(50, $limit));
        $excludeIds = \TinyCat\Support\Text::positive_int_ids($excludeIds);
        $fulltext = \TinyCat\Search\Queries::public_search_fulltext_query($query);
        $rows = [];

        try {
            $linkQuery = \TinyCat\Database\Queries::db_select(
                'SELECT c.id,
                        c.body,
                        c.author_id,
                        c.published_at,
                        u.username AS author_name,
                        u.username AS author_username,
                        u.avatar_exists AS author_avatar_exists,
                        u.updated_at AS author_updated_at,
                        CONCAT_WS(" ", l.title, l.description, l.normalized_url) AS link_excerpt
                    FROM content_links cl
                    INNER JOIN links l ON l.id = cl.link_id
                    INNER JOIN content c ON c.id = cl.content_id
                    INNER JOIN users u ON u.id = c.author_id'
            )
                ->where('u.status = ?', 'active')
                ->where('c.published_at <= ?', \TinyCat\Support\Dates::dateDb());

            if ($fulltext !== '' && \TinyCat\Search\Queries::public_search_fulltext_ready('links', 'links_search_fulltext')) {
                $linkQuery->where('MATCH(l.normalized_url, l.title, l.description) AGAINST (? IN BOOLEAN MODE)', $fulltext);
            } else {
                $like = '%' . $query . '%';
                $linkQuery->where(
                    '(
                            l.normalized_url LIKE ?
                            OR l.title LIKE ?
                            OR l.description LIKE ?
                        )',
                    $like,
                    $like,
                    $like
                );
            }

            if ($excludeIds !== []) {
                $linkQuery->where(
                    'c.id NOT IN (' . implode(', ', array_fill(0, count($excludeIds), '?')) . ')',
                    $excludeIds
                );
            }

            $seen = array_fill_keys($excludeIds, true);

            foreach ($linkQuery
                ->order('c.published_at DESC, c.id DESC')
                ->limit(max($limit, min(100, $limit * 4)))
                ->all() as $item) {
                $id = (int) ($item['id'] ?? 0);

                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $rows[] = $item;

                if (count($rows) >= $limit) {
                    break;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $rows;
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_content_rows(string $query, int $limit): array
    {
        $rows = [];
        $seen = [];

        foreach (\TinyCat\Search\Queries::public_search_recent_content_scan($query, $limit) as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id < 1 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $rows[] = $item;

            if (count($rows) >= $limit) {
                return $rows;
            }
        }

        $fulltext = \TinyCat\Search\Queries::public_search_fulltext_query($query);

        if ($fulltext !== '' && \TinyCat\Search\Queries::public_search_fulltext_ready('content', 'content_body_fulltext')) {
            try {
                $fulltextQuery = \TinyCat\Database\Queries::db_select(
                    'SELECT c.id,
                            c.body,
                            c.author_id,
                            c.published_at,
                            u.username AS author_name,
                            u.username AS author_username,
                            u.avatar_exists AS author_avatar_exists,
                            u.updated_at AS author_updated_at
                        FROM content c
                        INNER JOIN users u ON u.id = c.author_id'
                )
                    ->where('MATCH(c.body) AGAINST (? IN BOOLEAN MODE)', $fulltext)
                    ->where('u.status = ?', 'active')
                    ->where('c.published_at <= ?', \TinyCat\Support\Dates::dateDb());

                // The recent-content pass above already prioritizes new posts.
                // Keep this full-text fallback index-ordered: sorting every match
                // by publication time forces MySQL to read and sort the whole
                // match set before it can honour LIMIT.
                foreach ($fulltextQuery
                    ->limit(max($limit, min(100, $limit * 4)))
                    ->all() as $item) {
                    $id = (int) ($item['id'] ?? 0);

                    if ($id < 1 || isset($seen[$id]) || !\TinyCat\Search\Queries::public_search_text_contains((string) ($item['body'] ?? ''), $query)) {
                        continue;
                    }

                    $seen[$id] = true;
                    $rows[] = $item;

                    if (count($rows) >= $limit) {
                        break;
                    }
                }
            } catch (Throwable) {
                // Link search below can still provide useful results.
            }
        }

        if (count($rows) >= $limit) {
            return $rows;
        }

        foreach (\TinyCat\Search\Queries::public_search_link_content_rows($query, $limit - count($rows), array_keys($seen)) as $item) {
            $rows[] = $item;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array<mixed>
     */
    public static function public_search_suggestion_tags(string $query, int $limit): array
    {
        $tagQuery = \TinyCat\Status\Tags::status_tag_normalize($query);

        if ($tagQuery === '') {
            return [];
        }

        $tags = [];

        foreach (\TinyCat\Database\Queries::db_select('SELECT t.id, t.name FROM terms t')
            ->where('t.name LIKE ?', $tagQuery . '%')
            ->order('t.name ASC')
            ->limit($limit)
            ->all() as $tag) {
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
                'posts_count' => 0,
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
            'SELECT u.id, u.username, u.username AS name, u.avatar_exists, u.updated_at, u.bio
                FROM users u'
        )
            ->where('u.status = ?', 'active')
            ->where('u.username LIKE ?', $usernameQuery . '%')
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
    public static function public_search_suggestions(string $query, int $limit = 6): array
    {
        $query = \TinyCat\Search\Queries::public_search_normalize_query($query);
        $limit = max(1, min(8, $limit));

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
     * @return array<mixed>
     */
    public static function public_search_results(string $query, int $limit = 6): array
    {
        $query = \TinyCat\Search\Queries::public_search_normalize_query($query);
        $limit = max(1, min(12, $limit));

        if ($query === '' || \TinyCat\Search\Queries::public_search_query_too_short($query)) {
            return \TinyCat\Search\Queries::public_search_empty_result($query);
        }

        $like = '%' . $query . '%';
        $queryLength = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
        $shortSearch = $queryLength < 4;
        $tagQuery = \TinyCat\Status\Tags::status_tag_normalize($query);
        $tagLike = $tagQuery !== ''
            ? ($shortSearch ? $tagQuery . '%' : '%' . $tagQuery . '%')
            : '%' . $query . '%';
        $tags = [];
        $users = [];
        $content = [];
        $contentIds = [];

        foreach (\TinyCat\Database\Queries::db_select(
            'SELECT t.id,
                    t.name,
                    (
                        SELECT COUNT(*)
                        FROM content_tags ct
                        WHERE ct.term_id = t.id
                    ) AS posts_count
                FROM terms t'
        )
            ->where('t.name LIKE ?', $tagLike)
            ->order('posts_count DESC, t.name ASC')
            ->limit($limit)
            ->all() as $tag) {
            $name = \TinyCat\Status\Tags::status_tag_normalize((string) ($tag['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tags[] = [
                'id' => (int) ($tag['id'] ?? 0),
                'type' => 'tag',
                'title' => '#' . $name,
                'excerpt' => \TinyCat\Support\Localization::t('public.search_tag_posts', ['count' => (int) ($tag['posts_count'] ?? 0)]),
                'url' => \TinyCat\Status\Tags::tag_url($name),
                'posts_count' => (int) ($tag['posts_count'] ?? 0),
            ];
        }

        $userQuery = \TinyCat\Database\Queries::db_select(
            'SELECT u.id, u.username, u.username AS name, u.avatar_exists, u.updated_at, u.bio
                FROM users u'
        )->where('u.status = ?', 'active');
        $usernameQuery = \TinyCat\Users\Usernames::username_normalize(ltrim($query, '@'));

        if ($shortSearch) {
            if ($usernameQuery !== '') {
                $userQuery->where('u.username LIKE ?', $usernameQuery . '%');
            } else {
                $userQuery->where('1 = 0');
            }
        } else {
            $userQuery->where('u.username LIKE ?', $like);
        }

        foreach ($userQuery
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

        foreach (\TinyCat\Search\Queries::public_search_content_rows($query, $limit) as $item) {
            $id = (int) ($item['id'] ?? 0);
            $authorId = (int) ($item['author_id'] ?? 0);

            if ($id < 1 || $authorId < 1) {
                continue;
            }

            $content[] = \TinyCat\Search\Queries::public_search_content_result($item, $query, (string) ($item['link_excerpt'] ?? '') ?: null);
            $contentIds[$id] = true;
        }

        return [
            'query' => $query,
            'tags' => $tags,
            'users' => $users,
            'content' => $content,
        ];
    }
}
