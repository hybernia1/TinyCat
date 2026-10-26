<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class Images
{
    /**
     * @return array<mixed>|null
     */
    public static function status_image_record(int $contentId): ?array
    {
        return $contentId > 0 ? \TinyCat\Database\Connection::one('SELECT * FROM content_images WHERE content_id = ? LIMIT 1', [$contentId]) : null;
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_image_url(array $item): string
    {
        $hasSelectedPath = array_key_exists('image_path', $item) || array_key_exists('path', $item);
        $path = trim((string) ($item['image_path'] ?? $item['path'] ?? ''));

        if (!$hasSelectedPath) {
            $record = \TinyCat\Status\Images::status_image_record((int) ($item['id'] ?? $item['content_id'] ?? 0));
            $path = (string) ($record['path'] ?? '');
        }

        return \TinyCat\Status\ImageStorage::url($path);
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_image_alt_text(array $item): string
    {
        $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? $item['username'] ?? ''));
        $author = $author !== '' ? $author : \TinyCat\Site\Presentation::site_name();
        $body = \TinyCat\Site\Presentation::meta_text(\TinyCat\Status\Links::status_strip_external_urls((string) ($item['body'] ?? '')), 120);

        return $body !== ''
            ? \TinyCat\Support\Localization::t('public.status_image_alt', ['author' => $author, 'text' => $body])
            : \TinyCat\Support\Localization::t('public.status_image_alt_empty', ['author' => $author]);
    }

    /**
     * @param array<mixed> $item
     * @return array<mixed>|null
     */
    public static function status_image_jsonld(array $item): ?array
    {
        $url = \TinyCat\Status\Images::status_image_url($item);

        if ($url === '') {
            return null;
        }

        return array_filter([
            '@type' => 'ImageObject',
            'contentUrl' => \TinyCat\Site\Presentation::absolute_url($url),
            'url' => \TinyCat\Site\Presentation::absolute_url($url),
            'width' => max(0, (int) ($item['image_width'] ?? $item['width'] ?? 0)) ?: null,
            'height' => max(0, (int) ($item['image_height'] ?? $item['height'] ?? 0)) ?: null,
            'encodingFormat' => 'image/webp',
            'caption' => \TinyCat\Status\Images::status_image_alt_text($item),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public static function status_images_enabled(): bool
    {
        return (bool) \TinyCat\Settings\Store::config('content_images.enabled', true);
    }

    public static function status_image_max_upload_kb(): int
    {
        return max(20, min(1024, (int) \TinyCat\Settings\Store::config('content_images.max_upload_kb', 100)));
    }

    public static function status_image_max_upload_bytes(): int
    {
        return \TinyCat\Status\Images::status_image_max_upload_kb() * 1024;
    }

    /**
     * @return array<mixed>|null
     */
    public static function status_image_uploaded_file(): ?array
    {
        $file = $_FILES['image'] ?? null;

        return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }
}
