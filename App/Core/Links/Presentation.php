<?php
declare(strict_types=1);
namespace TinyCat\Core\Links;

/** Data-only link presentation shared by independently owned repositories. */
class Presentation extends Urls
{
    public static function status_link_image_exists(string $url): bool { return \TinyCat\Core\Files\RemoteImageCache::exists($url); }

    /** @param array<mixed> $link */
    public static function status_link_display_url(array $link): string
    {
        $url = (string) ($link['normalized_url'] ?? '');
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return trim($host . ($path !== '/' ? $path : ''));
    }

    public static function status_video_embed_allowed(string $url): bool
    {
        return Providers::embedAllowed($url);
    }

    /**
     * @param array<mixed> $link
     */
    public static function status_video_embed_url(array $link): string
    {
        return Providers::embedUrl($link);
    }

    /**
     * @param array<mixed> $link
     * @return array<mixed>
     */
    public static function status_video_thumbnail_sources(array $link): array
    {
        $youtube = Providers::youtubeThumbnails((string) ($link['normalized_url'] ?? ''));
        if ($youtube !== []) { return ['webp' => '', 'fallback' => $youtube['desktop'], 'mobile' => $youtube['mobile']]; }
        $image = (string) ($link['image_url'] ?? '');
        return ['webp' => '', 'fallback' => \TinyCat\Core\Files\RemoteImageCache::exists($image) ? $image : '', 'mobile' => ''];
    }
}
