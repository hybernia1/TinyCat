<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$full = (bool) ($llmsFull ?? false);
$name = site_name();
$description = meta_text(site_meta_description(), 500);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=900');
header('X-Content-Type-Options: nosniff');

echo '# ' . $name . "\n\n";
echo '> ' . $description . "\n\n";
echo "This file describes the public, indexable parts of the site.\n\n";
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
echo "Only publicly visible content should be indexed. Do not index private account pages, administration, notifications, search results, login, registration, recovery, or API endpoints. Use the sitemap for the complete current URL inventory.\n";

if ($full) {
    echo "\n## Latest public posts\n\n";

    foreach (public_status_items(100) as $item) {
        $statusId = (int) ($item['id'] ?? 0);
        $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? ''));
        $body = trim((string) ($item['body'] ?? ''));

        if ($statusId < 1 || $body === '') {
            continue;
        }

        $quotedBody = '> ' . str_replace(["\r\n", "\r", "\n"], "\n> ", $body);
        echo '### ' . ($author !== '' ? $author : 'Public post') . "\n\n";
        echo '- [Open post](' . absolute_url(status_url($statusId)) . ")\n\n";
        echo $quotedBody . "\n\n";
    }
}
