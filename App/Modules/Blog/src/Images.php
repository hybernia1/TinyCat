<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Database\Connection;

final class Images
{
    /** @return array<mixed>|null */
    public static function find(int $id): ?array { return Connection::find('blog_article_images', ['article_id' => $id]); }
    /** @param array<mixed> $article */
    public static function url(array $article): string { return ImageStorage::url((string) ($article['image_path'] ?? $article['path'] ?? '')); }
    public static function enabled(): bool { return (bool) Settings::get('blog.images.enabled', true); }
    /** @return array<mixed>|null */
    public static function uploaded(): ?array { $file = $_FILES['image'] ?? null; return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null; }
    /** @param array<mixed> $article
     * @return array<string, mixed>|null */
    public static function jsonld(array $article): ?array
    {
        $url = self::url($article); if ($url === '') { return null; }
        return ['@type' => 'ImageObject', 'contentUrl' => \TinyCat\Support\LocalUrls::absolute($url), 'url' => \TinyCat\Support\LocalUrls::absolute($url), 'width' => (int) ($article['image_width'] ?? 0), 'height' => (int) ($article['image_height'] ?? 0), 'encodingFormat' => 'image/webp', 'caption' => \TinyCat\Core\Ui\Site::meta_text((string) ($article['body'] ?? ''), 120)];
    }
}
