<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;

/** Content persistence. Social dependants supply their transactional cleanup explicitly. */
final class Posts
{
    /** @return array<mixed>|null */
    public static function find(int $id): ?array
    {
        return $id > 0 ? \TinyCat\Database\Queries::db_select('SELECT * FROM social_posts')->where('id = ?', $id)->limit(1)->one() : null;
    }

    /** @param array<mixed>|null $item
     * @param array<mixed>|null $actor */
    public static function canEdit(?array $item, ?array $actor): bool
    {
        return $item !== null && $actor !== null && (\TinyCat\Core\Identity\Roles::isAdmin($actor) || (int) ($item['author_id'] ?? 0) === (int) ($actor['id'] ?? 0));
    }

    /** @param array<string, mixed>|null $item */
    public static function visible(?array $item): bool
    {
        return $item !== null && ($item['status'] ?? 'published') === 'published' && (string) ($item['published_at'] ?? '') <= \TinyCat\Support\Dates::dateDb();
    }

    /** Prepared links and image files must exist before entering the transaction.
     * @param list<array<mixed>> $links
     * @param array<mixed>|null $image
     * @param array<mixed>|null $existingImage */
    public static function update(int $id, PostCreation $draft, array $links, ?array $image, bool $removeImage, ?array $existingImage): string
    {
        if ($draft->rejection !== null || $id < 1 || (trim($draft->body) === '' && $image === null && ($removeImage || $existingImage === null))) { throw new \InvalidArgumentException('An update requires accepted text or an image.'); }
        $payload = $draft->payload();
        return Transactions::run(static function () use ($id, $payload, $links, $image, $removeImage, $existingImage): string {
            Connection::update('social_posts', ['body' => $payload['body']], ['id' => $id]);
            Tags::status_sync_tags($id, $payload['tags']);
            Links::persistLinks($id, $links);
            if ($image !== null) {
                $data = ['path' => (string) $image['path'], 'width' => (int) $image['width'], 'height' => (int) $image['height'], 'bytes' => (int) $image['bytes']];
                if ($existingImage === null) { Connection::insert('social_post_images', ['content_id' => $id] + $data); }
                else { Connection::update('social_post_images', $data, ['content_id' => $id]); }
                return (string) ($existingImage['path'] ?? '');
            }
            if ($removeImage && $existingImage !== null) { Connection::delete('social_post_images', ['content_id' => $id]); return (string) ($existingImage['path'] ?? ''); }
            return '';
        });
    }

    /** The caller owns authorization. Related domain writes run in the same transaction.
     * @param (\Closure(): void)|null $beforeDelete */
    public static function delete(int $id, ?\Closure $beforeDelete = null, bool $deleteImageFile = true): string
    {
        if ($id < 1) { return ''; }
        if ($deleteImageFile && Connection::get()->inTransaction()) { throw new \LogicException('The outer transaction owner must defer image deletion until commit.'); }
        $terms = Tags::status_term_ids_for_content($id); $links = Links::status_link_ids_for_content($id);
        $image = Connection::one('SELECT * FROM social_post_images WHERE content_id = ? LIMIT 1', [$id]);
        Transactions::run(static function () use ($id, $beforeDelete, $terms, $links): void {
            if ($beforeDelete !== null) { $beforeDelete(); }
            foreach (Connection::all('SELECT id FROM social_comments WHERE content_id = ?', [$id]) as $comment) {
                Connection::delete('social_comment_likes', ['comment_id' => (int) $comment['id']]);
            }
            Connection::delete('social_comments', ['content_id' => $id]);
            Connection::delete('social_post_likes', ['content_id' => $id]);
            Interactions::invalidate();
            foreach (['social_post_tags', 'social_post_links', 'social_post_images'] as $table) { Connection::delete($table, ['content_id' => $id]); }
            Tags::status_cleanup_unused_term_ids($terms); Links::status_cleanup_unused_link_ids($links);
            Connection::delete('social_posts', ['id' => $id]);
        });
        $path = (string) ($image['path'] ?? '');
        if ($deleteImageFile) { ImageStorage::delete($path); }
        return $path;
    }
}
