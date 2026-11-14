<?php
declare(strict_types=1);

namespace TinyCat\Social\Content;

use TinyCat\Core\Identity\Deletion;

/** Publishing data and files remain owned by their domain. */
class AccountLifecycle
{
    /** @param array<mixed> $user */
    public static function prepareDelete(array $user): Deletion
    {
        $userId = (int) $user['id'];
        $termIds = array_map(
            static fn (array $row): int => (int) ($row['term_id'] ?? 0),
            \TinyCat\Database\Connection::all(
                'SELECT DISTINCT ct.term_id
                 FROM social_post_tags ct
                 INNER JOIN social_posts c ON c.id = ct.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );
        $linkIds = array_map(
            static fn (array $row): int => (int) ($row['link_id'] ?? 0),
            \TinyCat\Database\Connection::all(
                'SELECT DISTINCT cl.link_id
                 FROM social_post_links cl
                 INNER JOIN social_posts c ON c.id = cl.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );
        $imagePaths = array_map(
            static fn (array $row): string => (string) ($row['path'] ?? ''),
            \TinyCat\Database\Connection::all(
                'SELECT ci.path
                 FROM social_post_images ci
                 INNER JOIN social_posts c ON c.id = ci.content_id
                 WHERE c.author_id = ?',
                [$userId]
            )
        );

        return new Deletion(
            afterDelete: static function () use ($termIds, $linkIds): void {
                Interactions::invalidate();
                Tags::status_cleanup_unused_term_ids($termIds);
                Links::status_cleanup_unused_link_ids($linkIds);
            },
            afterCommit: static function () use ($imagePaths): void {
                foreach ($imagePaths as $path) { ImageStorage::delete($path); }
            },
        );
    }
}
