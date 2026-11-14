<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Public content projections without follower, reaction or comment tables. */
final class Queries
{
    public static function query(): \TinyCat\Database\Query
    {
        return Archive::visible(new \TinyCat\Database\Query('SELECT c.*, u.username AS author_username,
            u.avatar_exists AS author_avatar_exists, u.avatar_appearance AS author_avatar_appearance,
            ci.path AS image_path, ci.width AS image_width, ci.height AS image_height, ci.bytes AS image_bytes
            FROM social_posts c INNER JOIN users u ON u.id = c.author_id LEFT JOIN social_post_images ci ON ci.content_id = c.id'))
            ->where('u.status = ?', 'active');
    }

    /** @return array<mixed>|null */
    public static function find(int $id): ?array { return $id > 0 ? self::query()->where('c.id = ?', $id)->limit(1)->one() : null; }

    /** @return array<mixed> */
    public static function page(int $limit = 24, string $at = '', int $id = 0, int $author = 0, string $tag = '', string $media = ''): array
    {
        $query = self::query();
        [$cursor, $params] = Archive::cursor($at, $id); if ($cursor !== '') { $query->where($cursor, ...$params); }
        [$filter, $params] = Archive::predicate($media); if ($filter !== '') { $query->where($filter, ...$params); }
        if ($author > 0) { $query->where('c.author_id = ?', $author); }
        if ($tag !== '') { $query->where('EXISTS (SELECT 1 FROM social_post_tags ct INNER JOIN social_terms t ON t.id = ct.term_id WHERE ct.content_id = c.id AND t.name = ?)', Tags::status_tag_normalize($tag)); }
        return $query->order('c.published_at DESC, c.id DESC')->limit(max(1, min(100, $limit)))->all();
    }
}
