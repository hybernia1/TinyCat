<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use InvalidArgumentException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Owns TinyCat sitemap sections.
 */
final class SitemapData
{
    public static function count(string $section): int
    {
        $section = strtolower(trim($section));

        return match ($section) {
            'tags' => (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM social_terms t WHERE ' . self::publicTagPredicate(), [\TinyCat\Support\Dates::dateDb()]),
            'status' => (int) \TinyCat\Database\Connection::value(
                'SELECT COUNT(*)
                    FROM social_posts c
                    INNER JOIN users u ON u.id = c.author_id
                    WHERE u.status = ?
                        AND c.published_at <= ? AND c.status = \'published\' AND c.kind = \'post\'',
                ['active', \TinyCat\Support\Dates::dateDb()]
            ),
            default => throw new InvalidArgumentException('Sitemap section is not registered: ' . $section),
        };
    }

    private static function publicTagPredicate(): string
    {
        // Avoid indexing orphan tags and tags used only by hidden or future posts.
        return "EXISTS (SELECT 1 FROM social_post_tags ct INNER JOIN social_posts c ON c.id = ct.content_id
            INNER JOIN users u ON u.id = c.author_id WHERE ct.term_id = t.id AND u.status = 'active'
            AND c.published_at <= ? AND c.status = 'published' AND c.kind = 'post')";
    }

    /** @return list<array{url: string, last_modified: ?string, image_url?: string}> */
    public static function entries(string $section, int $limit, int $offset): array
    {
        $section = strtolower(trim($section));
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $rows = match ($section) {
            'tags' => \TinyCat\Database\Connection::all(
                'SELECT t.id, t.name
                    FROM social_terms t WHERE ' . self::publicTagPredicate() . '
                    ORDER BY t.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                [\TinyCat\Support\Dates::dateDb()]
            ),
            'status' => \TinyCat\Database\Connection::all(
                'SELECT c.id, ci.path AS image_path
                    FROM social_posts c
                    INNER JOIN users u ON u.id = c.author_id
                    LEFT JOIN social_post_images ci ON ci.content_id = c.id
                    WHERE u.status = ?
                        AND c.published_at <= ? AND c.status = \'published\' AND c.kind = \'post\'
                    ORDER BY c.id ASC
                    LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['active', \TinyCat\Support\Dates::dateDb()]
            ),
            default => throw new InvalidArgumentException('Sitemap section is not registered: ' . $section),
        };

        $entries = [];
        foreach ($rows as $row) {
            $url = match ($section) {
                'tags' => ($tag = \TinyCat\Social\Posts\Tags::status_tag_normalize((string) ($row['name'] ?? ''))) !== '' ? \TinyCat\Social\Posts\Tags::tag_url($tag) : '',
                default => \TinyCat\Social\Posts\Posts::status_url((int) ($row['id'] ?? 0)),
            };

            if ($url !== '') {
                $entry = [
                    'url' => $url,
                    'last_modified' => isset($row['last_modified']) ? (string) $row['last_modified'] : null,
                ];
                $imageUrl = \TinyCat\Social\Content\ImageStorage::url((string) ($row['image_path'] ?? ''));
                if ($imageUrl !== '') {
                    $entry['image_url'] = $imageUrl;
                }
                $entries[] = $entry;
            }
        }

        return $entries;
    }

}
