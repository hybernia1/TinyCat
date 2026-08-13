<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$full = (bool) ($llmsFull ?? false);
$name = site_name();
$primaryLocale = language_code((string) config('i18n.locale', config('install.locale', 'en')));
$primaryLocale = $primaryLocale !== '' ? $primaryLocale : 'en';
$availableLocales = array_keys(language_packages());

if (!in_array($primaryLocale, $availableLocales, true)) {
    $availableLocales[] = $primaryLocale;
    sort($availableLocales, SORT_STRING);
}

$configuredDescription = trim((string) config('site.meta_description', ''));
$description = $configuredDescription !== ''
    ? site_meta_description()
    : t('public.meta_description', ['site' => $name], 'en');

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=900');
header('X-Content-Type-Options: nosniff');

echo '# ' . $name . "\n\n";
echo '> ' . $description . "\n\n";
echo "This file describes the public, indexable parts of the site. The primary site language is `" . $primaryLocale
    . '`, and available interface locales are `' . implode('`, `', $availableLocales) . "`.\n\n";
echo "Public posts may contain text, one attached WebP image, and links with optional previews. The platform has no private messages, private profiles, or private storage; publicly accessible content is intended to be public. The feed has no personalization or recommendation algorithm. Visitors can tailor their following feed by following public profiles.\n\n";
echo "Use `GET /search?q={query}` with a URL-encoded query between 2 and 80 characters to find matching public tags, profiles, and posts. Search result pages are `noindex`, but their canonical public result URLs may be used for navigation. Respect rate limits and any CAPTCHA response; do not use API endpoints directly.\n\n";
echo "Public RSS feeds are available at `/tag/{tag}/feed` and `/author/{id}/feed`. Public URL patterns are `/author/{id}`, `/author/{id}/feed`, `/tag/{tag}`, `/tag/{tag}/feed`, and `/status/{id}`. Only publicly visible content should be indexed. Do not index private account pages, administration, notifications, search results, login, registration, recovery, or API endpoints. Use the sitemap and canonical page URLs as the complete, current URL inventory.\n\n";
echo "## Public resources\n\n";
echo '- [Homepage](' . absolute_url('/') . '): Public site homepage and latest posts.' . "\n";
echo '- [Sitemap](' . absolute_url('/sitemap.xml') . '): Complete index of public profiles, tags, and posts.' . "\n";
echo '- [Robots rules](' . absolute_url('/robots.txt') . '): Crawler access rules.' . "\n";
echo '- [Privacy and Rules](' . absolute_url('/privacy') . '): Public-content rules, moderation policy, data handling, and feed principles.' . "\n\n";
echo "## Optional\n\n";
echo '- [Recent public-post sample](' . absolute_url('/llms-full.txt') . '): TinyCat extension with a recent public-post sample; skip it when only site-level context is needed.' . "\n";

if ($full) {
    $posts = array_values(array_filter(
        public_status_items_cursor(100, '', 0, false),
        static fn (array $item): bool => (int) ($item['id'] ?? 0) > 0
            && (trim((string) ($item['body'] ?? '')) !== '' || status_image_url($item) !== '')
    ));

    echo "\n## Latest public posts\n\n";

    if ($posts === []) {
        echo "There are no public posts in this recent sample.\n";
        return;
    }

    $newestPublishedAt = date_iso((string) ($posts[0]['published_at'] ?? ''));
    $oldestPublishedAt = date_iso((string) ($posts[array_key_last($posts)]['published_at'] ?? ''));
    echo 'This is a recent sample of ' . count($posts) . ' public posts, covering publications from '
        . $oldestPublishedAt . ' to ' . $newestPublishedAt . ". Use the sitemap and RSS feeds for the complete, current inventory.\n\n";

    foreach ($posts as $item) {
        $statusId = (int) ($item['id'] ?? 0);
        $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? ''));
        $body = trim((string) ($item['body'] ?? ''));
        $imageUrl = status_image_url($item);
        $publishedAt = date_iso((string) ($item['published_at'] ?? ''));

        echo '### ' . ($author !== '' ? $author : 'Public post') . "\n\n";
        echo '- Published: ' . $publishedAt . "\n";
        echo '- [Open post](' . absolute_url(status_url($statusId)) . ")\n";
        if ($imageUrl !== '') {
            echo '- [Attached image](' . absolute_url($imageUrl) . ")\n";
        }
        if ($body !== '') {
            echo "\n> " . str_replace(["\r\n", "\r", "\n"], "\n> ", $body) . "\n\n";
        } else {
            echo "\n> Image-only post.\n\n";
        }
    }
}
