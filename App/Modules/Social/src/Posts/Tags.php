<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use Throwable;

final class Tags extends \TinyCat\Social\Content\Tags
{
    public static function tag_url(string $tag): string
    {
        $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize($tag);

        return \TinyCat\Support\LocalUrls::resolve($tag !== '' ? '/tag/' . rawurlencode($tag) : '/');
    }

    public static function tag_feed_url(string $tag, string $media = ''): string
    {
        $tag = \TinyCat\Social\Posts\Tags::status_tag_normalize($tag);

        $media = Feed::archive_type($media);
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
            $rows = \TinyCat\Database\Queries::db_select(
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
