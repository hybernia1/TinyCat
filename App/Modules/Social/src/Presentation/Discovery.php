<?php
declare(strict_types=1);

namespace TinyCat\Social\Presentation;

use TinyCat\Http\Document;
use TinyCat\Support\View;
use TinyCat\Support\Xml;

final class Discovery
{
    public static function handles(string $path): bool
    {
        return in_array($path, ['/robots.txt', '/robots', '/llms.txt', '/llms-full.txt', '/llms', '/sitemap.xml', '/sitemap'], true)
            || preg_match('~^/sitemap-[a-z][a-z0-9_-]{0,63}-[1-9][0-9]*[.]xml$~D', $path) === 1
            || preg_match('~^/(?:search|author/[0-9]+|tag/[^/]+)/feed$~D', $path) === 1;
    }

    public static function locale(): string
    {
        return \TinyCat\Support\Languages::language_code((string) \TinyCat\Core\Settings\Store::config('i18n.locale', \TinyCat\Core\Settings\Store::config('install.locale', 'en'))) ?: 'en';
    }

    public static function robots(): void
    {
        \TinyCat\Core\Discovery\Routes::robots();
    }

    public static function llms(): string
    {
        $body = View::capture(static function (): void {
            echo "## Social\n\n";
            echo "Public posts may contain text, one attached WebP image, and links with optional previews. The platform has no private messages, private profiles, or private storage; publicly accessible content is intended to be public. The feed has no personalization or recommendation algorithm. Visitors can tailor their following feed by following public profiles.\n\n";
            echo "Use `GET /search?q={query}` with a URL-encoded query between 3 and 80 characters to find matching public posts. Add `media=images`, `video`, `audio`, `links`, or `text` to filter the results. Search result pages are `noindex`, but their canonical public result URLs may be used for navigation. Respect rate limits and any CAPTCHA response; do not use API endpoints directly.\n\n";
            echo "Public RSS feeds are available at `/tag/{tag}/feed` and `/author/{id}/feed`. Public URL patterns are `/author/{id}`, `/author/{id}/feed`, `/tag/{tag}`, `/tag/{tag}/feed`, and `/status/{id}`. Only publicly visible content should be indexed. Do not index private account pages, administration, notifications, search results, login, registration, recovery, or API endpoints. Use the sitemap and canonical page URLs as the complete, current URL inventory.\n\n";
            echo "## Public resources\n\n";
            echo '- [Homepage](' . \TinyCat\Core\Ui\Site::absolute_url('/') . '): Public site homepage and latest posts.' . "\n";
            echo '- [Sitemap](' . \TinyCat\Core\Ui\Site::absolute_url('/sitemap.xml') . '): Complete index of public profiles, tags, and posts.' . "\n";
            echo '- [Robots rules](' . \TinyCat\Core\Ui\Site::absolute_url('/robots.txt') . '): Crawler access rules.' . "\n";
            echo '- [Privacy and Rules](' . \TinyCat\Core\Ui\Site::absolute_url('/privacy') . '): Public-content rules, moderation policy, data handling, and feed principles.' . "\n\n";
            echo "## Optional\n\n";
            echo '- [Recent public-post sample](' . \TinyCat\Core\Ui\Site::absolute_url('/llms-full.txt') . '): TinyCat extension with a recent public-post sample; skip it when only site-level context is needed.' . "\n";


        });
        return $body;
    }

    public static function sample(): string
    {
        return View::capture(static function (): void {
            $posts = array_values(array_filter(
                \TinyCat\Social\Posts\Export::posts(100),
                static fn (array $item): bool => (int) ($item['id'] ?? 0) > 0
                    && (trim((string) ($item['body'] ?? '')) !== '' || \TinyCat\Social\Content\Images::status_image_url($item) !== '')
            ));

            echo "\n## Latest public posts\n\n";

            if ($posts === []) {
                echo "There are no public posts in this recent sample.\n";
                return;
            }

            $newestPublishedAt = \TinyCat\Support\Dates::dateIso((string) ($posts[0]['published_at'] ?? ''));
            $oldestPublishedAt = \TinyCat\Support\Dates::dateIso((string) ($posts[array_key_last($posts)]['published_at'] ?? ''));
            echo 'This is a recent sample of ' . count($posts) . ' public posts, covering publications from '
                . $oldestPublishedAt . ' to ' . $newestPublishedAt . ". Use the sitemap and RSS feeds for the complete, current inventory.\n\n";

            foreach ($posts as $item) {
                $statusId = (int) ($item['id'] ?? 0);
                $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? ''));
                $body = trim((string) ($item['body'] ?? ''));
                $imageUrl = \TinyCat\Social\Content\Images::status_image_url($item);
                $publishedAt = \TinyCat\Support\Dates::dateIso((string) ($item['published_at'] ?? ''));

                echo '### ' . ($author !== '' ? $author : 'Public post') . "\n\n";
                echo '- Published: ' . $publishedAt . "\n";
                echo '- [Open post](' . \TinyCat\Core\Ui\Site::absolute_url(\TinyCat\Social\Posts\Posts::status_url($statusId)) . ")\n";
                if ($imageUrl !== '') {
                    echo '- [Attached image](' . \TinyCat\Core\Ui\Site::absolute_url($imageUrl) . ")\n";
                }
                if ($body !== '') {
                    echo "\n> " . str_replace(["\r\n", "\r", "\n"], "\n> ", $body) . "\n\n";
                } else {
                    echo "\n> Image-only post.\n\n";
                }
            }
        });
    }

    public static function sitemap(string $section = 'index', int $page = 1): void
    {
        \TinyCat\Core\Discovery\Sitemap::respond($section, $page);
    }
}
