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
echo "This file describes the public, indexable parts of the site.\n\n";
echo "## Site languages\n\n";
echo '- Primary site language: `' . $primaryLocale . "`.\n";
echo '- Available interface locales: `' . implode('`, `', $availableLocales) . "`.\n\n";
echo "## Public content and policies\n\n";
echo '- Public posts may contain text, one attached WebP image, and links with optional previews.' . "\n";
echo '- The platform has no private messages, private profiles, or private storage. Publicly accessible content is intended to be public.' . "\n";
echo '- The feed has no personalization or recommendation algorithm. Visitors can tailor their following feed by following public profiles.' . "\n";
echo '- [Privacy and Rules](' . absolute_url('/privacy') . '): Public-content rules, moderation policy, data handling, and feed principles.' . "\n\n";
echo "## Public resources\n\n";
echo '- [Homepage](' . absolute_url('/') . '): Public site homepage and latest posts.' . "\n";
echo '- [Sitemap](' . absolute_url('/sitemap.xml') . '): Complete index of public profiles, tags, and posts.' . "\n";
echo '- [Robots rules](' . absolute_url('/robots.txt') . '): Crawler access rules.' . "\n";
echo '- RSS feeds: Available at `/tag/{tag}/feed` and `/author/{id}/feed` for individual tags and profiles.' . "\n\n";
echo "## URL patterns\n\n";
echo '- `/author/{id}`: Public profile and posts by an active user.' . "\n";
echo '- `/author/{id}/feed`: RSS feed for an active user.' . "\n";
echo '- `/tag/{tag}`: Public posts belonging to a tag.' . "\n";
echo '- `/tag/{tag}/feed`: RSS feed for a tag.' . "\n";
echo '- `/status/{id}`: Individual public post.' . "\n\n";
echo "## Indexing guidance\n\n";
echo "Only publicly visible content should be indexed. Do not index private account pages, administration, notifications, search results, login, registration, recovery, or API endpoints. Use the sitemap and canonical page URLs as the complete, current URL inventory.\n";

if ($full) {
    $posts = array_values(array_filter(
        public_status_items_cursor(100),
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
            echo '\n> ' . str_replace(["\r\n", "\r", "\n"], "\n> ", $body) . "\n\n";
        } else {
            echo "\n> Image-only post.\n\n";
        }
    }
}
