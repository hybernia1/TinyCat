<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Database\Connection;
use TinyCat\Support\Dates;

/** Only the public fields needed by syndication and crawler documents. */
final class Export
{
    /** @return array<mixed> */
    public static function posts(int $limit = 100, ?int $authorId = null, ?int $termId = null, string $media = ''): array
    {
        $limit = max(1, min(100, $limit));
        $query = $termId !== null ? Feed::public_status_tag_id_query($termId)
            : ($authorId !== null ? Feed::public_status_author_id_query() : Feed::public_status_id_query());
        if ($authorId !== null) { $query->where('c.author_id = ?', $authorId); }
        [$predicate, $params] = Feed::archive_predicate($media);
        if ($predicate !== '') { $query->where($predicate, ...$params); }
        $ids = Feed::public_status_ids_page($query, $limit);
        return self::byIds($ids);
    }

    /** @param list<int> $ids
     * @return array<mixed> */
    public static function byIds(array $ids): array
    {
        if ($ids === []) { return []; }
        // Reuse the indexed feed ID selection, then load only export fields.
        // Recheck visibility in case an author or publication changed between queries.
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        return Connection::all('SELECT c.id, c.body, c.published_at, c.author_id, u.username AS author_name,
            ci.path AS image_path, ci.bytes AS image_bytes
            FROM social_posts c INNER JOIN users u ON u.id = c.author_id
            LEFT JOIN social_post_images ci ON ci.content_id = c.id
            WHERE u.status = ? AND c.published_at <= ? AND c.kind = \'post\' AND c.status = \'published\' AND c.id IN (' . $placeholders . ')
            ORDER BY c.published_at DESC, c.id DESC', ['active', Dates::dateDb(), ...$ids]);
    }
}
