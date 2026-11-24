<?php
declare(strict_types=1);

namespace TinyCat\Social\Site;

use TinyCat\Social\Posts\Export;

final class Discovery
{

    public static function llms(): string
    {
        $body = "## Social\n\n";
        $body .= "Public posts may contain text, one attached WebP image, and links with optional previews. The platform has no private messages, private profiles, or private storage; publicly accessible content is intended to be public. The feed has no personalization or recommendation algorithm. Visitors can tailor their following feed by following public profiles.\n\n";
        $body .= "Use `GET /search/all?q={query}&collection=content` with a URL-encoded query between 3 and 80 characters to find matching public posts. Search result pages are `noindex`, but their canonical public result URLs may be used for navigation. Respect rate limits and any CAPTCHA response; do not use API endpoints directly.\n\n";
        $body .= "Public RSS feeds are available at `/tag/{tag}/feed` and `/author/{id}/feed`. Public URL patterns are `/author/{id}`, `/author/{id}/feed`, `/tag/{tag}`, `/tag/{tag}/feed`, and `/status/{id}`. Only publicly visible content should be indexed. Do not index private account pages, administration, notifications, search results, login, registration, recovery, or API endpoints. Use the sitemap and canonical page URLs as the complete, current URL inventory.\n\n";
        $body .= "## Optional\n\n";
        $body .= '- [Recent public-post sample](' . \TinyCat\Core\Ui\Site::absolute_url('/llms-full.txt') . '): TinyCat extension with a recent public-post sample; skip it when only site-level context is needed.' . "\n";

        return $body;
    }

    public static function sample(): string
    {
        $posts = array_values(array_filter(
            Export::posts(100),
            static fn (array $item): bool => (int) ($item['id'] ?? 0) > 0
                && (trim((string) ($item['body'] ?? '')) !== '' || \TinyCat\Social\Posts\Images::status_image_url($item) !== '')
        ));
        $body = "\n## Latest public posts\n\n";
        if ($posts === []) { return $body . "There are no public posts in this recent sample.\n"; }

        $newestPublishedAt = \TinyCat\Support\Dates::dateIso((string) ($posts[0]['published_at'] ?? ''));
        $oldestPublishedAt = \TinyCat\Support\Dates::dateIso((string) ($posts[array_key_last($posts)]['published_at'] ?? ''));
        $body .= 'This is a recent sample of ' . count($posts) . ' public posts, covering publications from '
            . $oldestPublishedAt . ' to ' . $newestPublishedAt . ". Use the sitemap and RSS feeds for the complete, current inventory.\n\n";

        foreach ($posts as $item) {
            $statusId = (int) ($item['id'] ?? 0);
            $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? ''));
            $text = trim((string) ($item['body'] ?? ''));
            $imageUrl = \TinyCat\Social\Posts\Images::status_image_url($item);
            $publishedAt = \TinyCat\Support\Dates::dateIso((string) ($item['published_at'] ?? ''));

            $body .= '### ' . ($author !== '' ? $author : 'Public post') . "\n\n";
            $body .= '- Published: ' . $publishedAt . "\n";
            $body .= '- [Open post](' . \TinyCat\Core\Ui\Site::absolute_url(\TinyCat\Social\Posts\Posts::status_url($statusId)) . ")\n";
            if ($imageUrl !== '') {
                $body .= '- [Attached image](' . \TinyCat\Core\Ui\Site::absolute_url($imageUrl) . ")\n";
            }
            if ($text !== '') {
                $body .= "\n> " . str_replace("\n", "\n> ", str_replace(["\r\n", "\r"], "\n", $text)) . "\n\n";
            } else {
                $body .= "\n> Image-only post.\n\n";
            }
        }
        return $body;
    }

}
