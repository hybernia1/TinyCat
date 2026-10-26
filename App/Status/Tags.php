<?php
declare(strict_types=1);

namespace TinyCat\Status;

use Throwable;

final class Tags
{
    public static function tag_url(string $tag): string
    {
        $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);

        return $tag !== '' ? '/tag/' . rawurlencode($tag) : '/';
    }

    public static function tag_feed_url(string $tag): string
    {
        $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);

        return $tag !== '' ? '/tag/' . rawurlencode($tag) . '/feed' : '/';
    }

    /**
     * @return array<mixed>
     */
    public static function public_trending_tags(int $limit = 8, int $days = 7, bool $compute = true): array
    {
        $limit = max(1, min(30, $limit));
        $days = max(1, min(365, $days));
        $since = \TinyCat\Support\Dates::dateDb('-' . $days . ' days');
        $cacheKey = 'public_trending_tags_' . $limit . '_' . $days;

        $cached = \TinyCat\Support\Cache::get($cacheKey, 3600);

        if (is_array($cached)) {
            return $cached;
        }

        if (!$compute) {
            $stale = \TinyCat\Support\Cache::read($cacheKey);

            return is_array($stale) ? $stale : [];
        }

        $feedIndex = (string) \TinyCat\Settings\Store::config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_sidebar_index)'
            : '';
        $rows = \TinyCat\Database\Queries::db_select(
            'SELECT t.id,
                    t.name,
                    COUNT(*) AS posts_count,
                    MAX(c.published_at) AS latest_at
                FROM content c' . $feedIndex . '
                INNER JOIN users u ON u.id = c.author_id
                INNER JOIN content_tags ct ON ct.content_id = c.id
                INNER JOIN terms t ON t.id = ct.term_id'
        )
            ->where('c.published_at >= ?', $since)
            ->where('c.published_at <= ?', \TinyCat\Support\Dates::dateDb())
            ->where('u.status = ?', 'active')
            ->group('t.id, t.name')
            ->order('posts_count DESC, latest_at DESC, t.name ASC')
            ->limit($limit)
            ->all();

        $tags = [];

        foreach ($rows as $row) {
            $name = \TinyCat\Status\Tags::status_tag_normalize((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tags[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => $name,
                'url' => \TinyCat\Status\Tags::tag_url($name),
                'posts_count' => (int) ($row['posts_count'] ?? 0),
            ];
        }

        \TinyCat\Support\Cache::put($cacheKey, $tags);

        return $tags;
    }

    public static function status_tag_normalize(string $tag): string
    {
        $tag = trim($tag);
        $tag = ltrim($tag, "# \t\n\r\0\x0B");
        $tag = \TinyCat\Support\Text::slug($tag);

        return strlen($tag) <= \TinyCat\Status\Tags::status_tag_max_length() ? $tag : '';
    }

    public static function status_tag_max_length(): int
    {
        return 32;
    }

    /**
     * @return array<mixed>
     */
    public static function status_tag_tokens_from_text(string $text): array
    {
        if (!preg_match_all('/(?<![\\p{L}\\p{N}_])#([\\p{L}\\p{N}][\\p{L}\\p{N}_-]*)/u', $text, $matches)) {
            return [];
        }

        return array_map('strval', (array) $matches[1]);
    }

    /**
     * @return array<mixed>
     */
    public static function status_tags_from_text(string $text): array
    {
        $tags = [];

        foreach (\TinyCat\Status\Tags::status_tag_tokens_from_text($text) as $item) {
            $tag = \TinyCat\Status\Tags::status_tag_normalize((string) $item);

            if ($tag !== '') {
                $tags[$tag] = $tag;
            }
        }

        return array_values($tags);
    }

    public static function normalize_tags_for_storage(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\\p{L}\\p{N}_])#([\\p{L}\\p{N}][\\p{L}\\p{N}_-]*)/u',
            static function (array $match): string {
                $tag = \TinyCat\Status\Tags::status_tag_normalize((string) $match[1]);

                return $tag !== '' ? '#' . $tag : (string) $match[0];
            },
            $text
        );
    }

    /**
     * @return array<mixed>
     */
    public static function status_tag_suggestions(): array
    {
        static $tags = null;

        if ($tags !== null) {
            return $tags;
        }

        $tags = [];

        try {
            foreach (\TinyCat\Database\Queries::db_select('SELECT name FROM terms')->order('name ASC')->limit(300)->all() as $row) {
                $tag = \TinyCat\Status\Tags::status_tag_normalize((string) ($row['name'] ?? ''));

                if ($tag !== '') {
                    $tags[$tag] = $tag;
                }
            }
        } catch (Throwable) {
            $tags = [];
        }

        return array_values($tags);
    }

    public static function status_term_id(string $tag): int
    {
        $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        $term = \TinyCat\Database\Queries::db_select('SELECT id, name FROM terms')
            ->where('name = ?', $tag)
            ->limit(1)
            ->one();
        $id = (int) ($term['id'] ?? 0);

        if ($id > 0) {
            if ((string) ($term['name'] ?? '') !== $tag) {
                \TinyCat\Database\Connection::update('terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }

        try {
            return (int) \TinyCat\Database\Connection::insert('terms', ['name' => $tag]);
        } catch (Throwable $exception) {
            if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                throw $exception;
            }

            $term = \TinyCat\Database\Queries::db_select('SELECT id, name FROM terms')
                ->where('name = ?', $tag)
                ->limit(1)
                ->one();
            $id = (int) ($term['id'] ?? 0);

            if ($id > 0 && (string) ($term['name'] ?? '') !== $tag) {
                \TinyCat\Database\Connection::update('terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }
    }

    public static function status_term_id_exact(string $tag): int
    {
        $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        foreach (\TinyCat\Database\Queries::db_select('SELECT id, name FROM terms')->where('name = ?', $tag)->all() as $term) {
            if ((string) ($term['name'] ?? '') === $tag) {
                return (int) ($term['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param array<mixed> $tags
     */
    public static function status_sync_tags(int $contentId, array $tags): void
    {
        if ($contentId < 1) {
            return;
        }

        \TinyCat\Database\Transactions::run(static function () use ($contentId, $tags): void {
            $previousTermIds = \TinyCat\Status\Tags::status_term_ids_for_content($contentId);
            \TinyCat\Database\Connection::delete('content_tags', ['content_id' => $contentId]);

            foreach ($tags as $tag) {
                $termId = \TinyCat\Status\Tags::status_term_id((string) $tag);

                if ($termId < 1) {
                    continue;
                }

                try {
                    \TinyCat\Database\Connection::insert('content_tags', [
                        'content_id' => $contentId,
                        'term_id' => $termId,
                    ]);
                } catch (Throwable $exception) {
                    if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                        throw $exception;
                    }
                    // A duplicate pair can happen under a concurrent attach.
                }
            }

            \TinyCat\Status\Tags::status_cleanup_unused_term_ids($previousTermIds);
        });
    }

    /**
     * @return array<mixed>
     */
    public static function status_term_ids_for_content(int $contentId): array
    {
        if ($contentId < 1) {
            return [];
        }

        return \TinyCat\Support\Text::positive_int_ids(array_column(
            \TinyCat\Database\Connection::all('SELECT term_id FROM content_tags WHERE content_id = ?', [$contentId]),
            'term_id'
        ));
    }

    /**
     * @param array<mixed> $termIds
     */
    public static function status_cleanup_unused_term_ids(array $termIds): void
    {
        $termIds = \TinyCat\Support\Text::positive_int_ids($termIds);

        if ($termIds === []) {
            return;
        }

        foreach (array_chunk($termIds, 100) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            \TinyCat\Database\Connection::exec(
                'DELETE FROM terms
                    WHERE id IN (' . $placeholders . ')
                        AND NOT EXISTS (
                            SELECT 1
                            FROM content_tags ct
                            WHERE ct.term_id = terms.id
                        )',
                $chunk
            );
        }
    }
}
