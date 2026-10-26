<?php
declare(strict_types=1);

namespace TinyCat\Site;

use TinyCat\Http\Document;
use TinyCat\Support\View;
use TinyCat\Support\Xml;

final class Discovery
{
    public static function handles(string $path): bool
    {
        return in_array($path, ['/robots.txt', '/robots', '/llms.txt', '/llms-full.txt', '/llms', '/sitemap.xml', '/sitemap'], true)
            || preg_match('~^/sitemap-[a-z][a-z0-9_-]{0,63}-[1-9][0-9]*[.]xml$~D', $path) === 1
            || preg_match('~^/(?:author/[0-9]+|tag/[^/]+)/feed$~D', $path) === 1;
    }

    public static function locale(): string
    {
        return \TinyCat\Support\Languages::language_code((string) \TinyCat\Settings\Store::config('i18n.locale', \TinyCat\Settings\Store::config('install.locale', 'en'))) ?: 'en';
    }

    public static function robots(): void
    {
        $body = View::capture(static function (): void {
            echo '# AI and LLM context: ' . \TinyCat\Site\Presentation::absolute_url('/llms.txt') . "\n";
            echo '# Recent public-post sample for LLMs: ' . \TinyCat\Site\Presentation::absolute_url('/llms-full.txt') . "\n";
            echo "User-agent: *\n";
            echo "Disallow: /admin\n";
            echo "Disallow: /api\n";
            echo "Disallow: /install\n";
            echo "Disallow: /login\n";
            echo "Disallow: /logout\n";
            echo "Disallow: /notifications\n";
            echo "Disallow: /recovery\n";
            echo "Disallow: /register\n";
            echo "Disallow: /search\n";
            echo "Sitemap: " . \TinyCat\Site\Presentation::absolute_url('/sitemap.xml') . "\n";
        });
        Document::respond($body, 'text/plain', 3600);
    }

    public static function llms(bool $full = false): void
    {
        $body = View::capture(static function () use ($full): void {
            $name = \TinyCat\Site\Presentation::site_name();
            $primaryLocale = \TinyCat\Support\Languages::language_code((string) \TinyCat\Settings\Store::config('i18n.locale', \TinyCat\Settings\Store::config('install.locale', 'en')));
            $primaryLocale = $primaryLocale !== '' ? $primaryLocale : 'en';
            $availableLocales = array_keys(\TinyCat\Support\Languages::language_packages());

            if (!in_array($primaryLocale, $availableLocales, true)) {
                $availableLocales[] = $primaryLocale;
                sort($availableLocales, SORT_STRING);
            }

            $configuredDescription = trim((string) \TinyCat\Settings\Store::config('site.meta_description', ''));
            $description = $configuredDescription !== ''
                ? \TinyCat\Site\Presentation::site_meta_description()
                : \TinyCat\Support\Localization::t('public.meta_description', ['site' => $name], 'en');

            echo '# ' . $name . "\n\n";
            echo '> ' . $description . "\n\n";
            echo "This file describes the public, indexable parts of the site. The primary site language is `" . $primaryLocale
                . '`, and available interface locales are `' . implode('`, `', $availableLocales) . "`.\n\n";
            echo "Public posts may contain text, one attached WebP image, and links with optional previews. The platform has no private messages, private profiles, or private storage; publicly accessible content is intended to be public. The feed has no personalization or recommendation algorithm. Visitors can tailor their following feed by following public profiles.\n\n";
            echo "Use `GET /search?q={query}` with a URL-encoded query between 2 and 80 characters to find matching public tags, profiles, and posts. Search result pages are `noindex`, but their canonical public result URLs may be used for navigation. Respect rate limits and any CAPTCHA response; do not use API endpoints directly.\n\n";
            echo "Public RSS feeds are available at `/tag/{tag}/feed` and `/author/{id}/feed`. Public URL patterns are `/author/{id}`, `/author/{id}/feed`, `/tag/{tag}`, `/tag/{tag}/feed`, and `/status/{id}`. Only publicly visible content should be indexed. Do not index private account pages, administration, notifications, search results, login, registration, recovery, or API endpoints. Use the sitemap and canonical page URLs as the complete, current URL inventory.\n\n";
            echo "## Public resources\n\n";
            echo '- [Homepage](' . \TinyCat\Site\Presentation::absolute_url('/') . '): Public site homepage and latest posts.' . "\n";
            echo '- [Sitemap](' . \TinyCat\Site\Presentation::absolute_url('/sitemap.xml') . '): Complete index of public profiles, tags, and posts.' . "\n";
            echo '- [Robots rules](' . \TinyCat\Site\Presentation::absolute_url('/robots.txt') . '): Crawler access rules.' . "\n";
            echo '- [Privacy and Rules](' . \TinyCat\Site\Presentation::absolute_url('/privacy') . '): Public-content rules, moderation policy, data handling, and feed principles.' . "\n\n";
            echo "## Optional\n\n";
            echo '- [Recent public-post sample](' . \TinyCat\Site\Presentation::absolute_url('/llms-full.txt') . '): TinyCat extension with a recent public-post sample; skip it when only site-level context is needed.' . "\n";

            if ($full) {
                $posts = array_values(array_filter(
                    \TinyCat\Status\Export::posts(100),
                    static fn (array $item): bool => (int) ($item['id'] ?? 0) > 0
                        && (trim((string) ($item['body'] ?? '')) !== '' || \TinyCat\Status\Images::status_image_url($item) !== '')
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
                    $imageUrl = \TinyCat\Status\Images::status_image_url($item);
                    $publishedAt = \TinyCat\Support\Dates::dateIso((string) ($item['published_at'] ?? ''));

                    echo '### ' . ($author !== '' ? $author : 'Public post') . "\n\n";
                    echo '- Published: ' . $publishedAt . "\n";
                    echo '- [Open post](' . \TinyCat\Site\Presentation::absolute_url(\TinyCat\Status\Posts::status_url($statusId)) . ")\n";
                    if ($imageUrl !== '') {
                        echo '- [Attached image](' . \TinyCat\Site\Presentation::absolute_url($imageUrl) . ")\n";
                    }
                    if ($body !== '') {
                        echo "\n> " . str_replace(["\r\n", "\r", "\n"], "\n> ", $body) . "\n\n";
                    } else {
                        echo "\n> Image-only post.\n\n";
                    }
                }
            }
        });
        Document::respond($body, 'text/plain', 900);
    }

    public static function sitemap(string $section = 'index', int $page = 1): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        if ($section === 'index') {
            $body .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach (Sitemap::pageCounts() as $name => $pages) {
                for ($part = 1; $part <= $pages; $part++) {
                    $body .= '<sitemap><loc>' . Xml::escape(Presentation::absolute_url(Sitemap::url($name, $part))) . "</loc></sitemap>\n";
                }
            }
            $body .= "</sitemapindex>\n";
        } else {
            if ($page > intdiv(PHP_INT_MAX, 1000) || !Sitemap::hasSection($section)) { Document::notFound('Sitemap not found.'); return; }
            $rows = Sitemap::entries($section, 1000, (max(1, $page) - 1) * 1000);
            if ($rows === []) { Document::notFound('Sitemap page not found.'); return; }
            $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
            foreach ($rows as $row) {
                $body .= '<url><loc>' . Xml::escape(Presentation::absolute_url($row['url'])) . '</loc>';
                if (($row['image_url'] ?? '') !== '') {
                    $body .= '<image:image><image:loc>' . Xml::escape(Presentation::absolute_url($row['image_url'])) . '</image:loc></image:image>';
                }
                $body .= "</url>\n";
            }
            $body .= "</urlset>\n";
        }
        Document::respond($body, 'application/xml', 900);
    }
}
