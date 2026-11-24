<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use Throwable;

final class Tags
{
    public static function status_tag_normalize(string $tag): string { return \TinyCat\Core\Text\Hashtags::normalize($tag, self::status_tag_max_length()); }
    public static function status_tag_max_length(): int { return 32; }
    /** @return list<string> */
    public static function status_tag_tokens_from_text(string $text): array { return \TinyCat\Core\Text\Hashtags::tokens($text); }
    /** @return list<string> */
    public static function status_tags_from_text(string $text): array { return \TinyCat\Core\Text\Hashtags::extract($text, self::status_tag_max_length()); }
    public static function normalize_tags_for_storage(string $text): string { return \TinyCat\Core\Text\Hashtags::normalizeText($text, self::status_tag_max_length()); }

    public static function status_term_id(string $tag): int
    {
        $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        $term = \TinyCat\Database\Query::select('SELECT id, name FROM social_terms')
            ->where('name = ?', $tag)
            ->limit(1)
            ->one();
        $id = (int) ($term['id'] ?? 0);

        if ($id > 0) {
            if ((string) ($term['name'] ?? '') !== $tag) {
                \TinyCat\Database\Connection::update('social_terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }

        try {
            return (int) \TinyCat\Database\Connection::insert('social_terms', ['name' => $tag]);
        } catch (Throwable $exception) {
            if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                throw $exception;
            }

            $term = \TinyCat\Database\Query::select('SELECT id, name FROM social_terms')
                ->where('name = ?', $tag)
                ->limit(1)
                ->one();
            $id = (int) ($term['id'] ?? 0);

            if ($id > 0 && (string) ($term['name'] ?? '') !== $tag) {
                \TinyCat\Database\Connection::update('social_terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }
    }

    public static function status_term_id_exact(string $tag): int
    {
        $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        foreach (\TinyCat\Database\Query::select('SELECT id, name FROM social_terms')->where('name = ?', $tag)->all() as $term) {
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
            $previousTermIds = \TinyCat\Social\Posts\Tags::status_term_ids_for_content($contentId);
            \TinyCat\Database\Connection::delete('social_post_tags', ['content_id' => $contentId]);

            foreach ($tags as $tag) {
                $termId = \TinyCat\Social\Posts\Tags::status_term_id((string) $tag);

                if ($termId < 1) {
                    continue;
                }

                try {
                    \TinyCat\Database\Connection::insert('social_post_tags', [
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

            \TinyCat\Social\Posts\Tags::status_cleanup_unused_term_ids($previousTermIds);
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
            \TinyCat\Database\Connection::all('SELECT term_id FROM social_post_tags WHERE content_id = ?', [$contentId]),
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
                'DELETE FROM social_terms
                    WHERE id IN (' . $placeholders . ')
                        AND NOT EXISTS (
                            SELECT 1
                            FROM social_post_tags ct
                            WHERE ct.term_id = social_terms.id
                        )',
                $chunk
            );
        }
    }

    public static function tag_url(string $tag): string
    {
        $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize($tag);

        return \TinyCat\Support\LocalUrls::resolve($tag !== '' ? '/tag/' . rawurlencode($tag) : '/');
    }

    public static function tag_feed_url(string $tag, string $media = ''): string
    {
        $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize($tag);

        $media = Archive::type($media);
        return $tag !== '' ? '/tag/' . rawurlencode($tag) . '/feed' . ($media !== '' ? '?media=' . $media : '') : '/';
    }

    /**
     * @return array<mixed>
     */
    public static function public_trending_tags(int $limit = 8, int $days = 7, bool $compute = true): array
    {
        $limit = max(1, min(30, $limit));
        $days = max(1, min(365, $days));
        $since = \TinyCat\Support\Dates::dateDb('-' . $days . ' days');
        $cacheKey = 'public_trending_tags_v3_' . $limit . '_' . $days;

        $cached = \TinyCat\Support\Cache::get($cacheKey, 3600);

        if (is_array($cached)) {
            return $cached;
        }

        if (!$compute) {
            $stale = \TinyCat\Support\Cache::read($cacheKey);

            return is_array($stale) ? $stale : [];
        }

        return \TinyCat\Support\Cache::rememberArray($cacheKey, 3600, static function () use ($limit, $since): array {
            $feedIndex = \TinyCat\Database\Connection::driver() === 'mysql'
                ? ' FORCE INDEX (content_sidebar_index)'
                : '';
            $rows = \TinyCat\Database\Query::select(
                'SELECT t.id,
                        t.name,
                        COUNT(*) AS posts_count,
                        MAX(c.published_at) AS latest_at
                    FROM social_posts c' . $feedIndex . '
                    INNER JOIN users u ON u.id = c.author_id
                    INNER JOIN social_post_tags ct ON ct.content_id = c.id
                    INNER JOIN social_terms t ON t.id = ct.term_id'
            )
                ->where('c.kind = ?', 'post')
                ->where('c.published_at >= ?', $since)
                ->where('c.published_at <= ? AND c.status = \'published\'', \TinyCat\Support\Dates::dateDb())
                ->where('u.status = ?', 'active')
                ->group('t.id, t.name')
                ->order('posts_count DESC, latest_at DESC, t.name ASC')
                ->limit($limit)
                ->all();

            $tags = [];

            foreach ($rows as $row) {
                $name = \TinyCat\Social\Posts\Tags::status_tag_normalize((string) ($row['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $tags[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => $name,
                    'url' => '/tag/' . rawurlencode($name),
                    'posts_count' => (int) ($row['posts_count'] ?? 0),
                ];
            }

            return $tags;
        });
    }

}
