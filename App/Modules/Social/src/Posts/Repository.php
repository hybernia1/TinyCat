<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Social\Posts\Links;
use TinyCat\Social\Discussion\Interactions;
use TinyCat\Social\Posts\ImageStorage;

use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;

/** Post persistence. Related cleanup shares the owning transaction. */
final class Repository
{
    /** @return array<mixed>|null */
    public static function find(int $id): ?array
    {
        return $id > 0 ? Connection::find('social_posts', ['id' => $id]) : null;
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
     * The caller deletes the returned superseded path only after the outer commit.
     * @param list<array<mixed>> $links
     * @param array<mixed>|null $image
     * @param array<mixed> $actor */
    public static function update(int $id, PostCreation $draft, array $links, ?array $image, bool $removeImage, array $actor): string
    {
        if ($draft->rejection !== null || $id < 1) { throw new \InvalidArgumentException('An update requires accepted text or an image.'); }
        $payload = $draft->payload();
        return Transactions::run(static function () use ($id, $payload, $links, $image, $removeImage, $actor): string {
            $current = Connection::one(Connection::lockingSelect('SELECT * FROM social_posts WHERE id = ?'), [$id]);
            if (!Posts::status_can_edit($current, $actor)) {
                throw new \TinyCat\Http\HttpException(\TinyCat\Support\Localization::t('account.messages.status_forbidden'), 403, 'status_forbidden');
            }
            $existingImage = Connection::one(Connection::lockingSelect('SELECT * FROM social_post_images WHERE content_id = ?'), [$id]);
            if (trim($payload['body']) === '' && $image === null && ($removeImage || $existingImage === null)) {
                throw new \TinyCat\Http\HttpException(\TinyCat\Support\Localization::t('account.messages.status_required'), 422, 'status_required', ['errors' => ['body' => [\TinyCat\Support\Localization::t('account.messages.status_required')]]]);
            }
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
        $path = Transactions::run(static function () use ($id, $beforeDelete): string {
            // Serialize with image replacement before reading any cleanup references.
            Connection::one(Connection::lockingSelect('SELECT id FROM social_posts WHERE id = ?'), [$id]);
            $terms = Tags::status_term_ids_for_content($id); $links = Links::status_link_ids_for_content($id);
            $image = Connection::one(Connection::lockingSelect('SELECT * FROM social_post_images WHERE content_id = ?'), [$id]);
            if ($beforeDelete !== null) { $beforeDelete(); }
            Connection::exec('DELETE FROM social_comment_likes WHERE comment_id IN (SELECT id FROM social_comments WHERE content_id = ?)', [$id]);
            Connection::delete('social_comments', ['content_id' => $id]);
            Connection::delete('social_post_likes', ['content_id' => $id]);
            Interactions::invalidate();
            foreach (['social_post_tags', 'social_post_links', 'social_post_images'] as $table) { Connection::delete($table, ['content_id' => $id]); }
            Tags::status_cleanup_unused_term_ids($terms); Links::status_cleanup_unused_link_ids($links);
            Connection::delete('social_posts', ['id' => $id]);
            return (string) ($image['path'] ?? '');
        });
        if ($deleteImageFile) { ImageStorage::delete($path); }
        return $path;
    }

    public static function redirectTarget(int $id): ?int
    {
        $target = \TinyCat\Database\Connection::value("SELECT c.id FROM social_post_redirects r
            INNER JOIN social_posts c ON c.id=r.content_id INNER JOIN users u ON u.id=c.author_id
            WHERE r.old_id=? AND c.status='published' AND c.published_at<=? AND u.status='active'", [$id, \TinyCat\Support\Dates::dateDb()]);
        return $target === null ? null : (int) $target;
    }
}
