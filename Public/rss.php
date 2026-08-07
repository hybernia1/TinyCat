<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$rssType = (string) ($rssType ?? '');
$rssTitle = '';
$rssDescription = '';
$rssLink = '/';
$rssItems = [];

if ($rssType === 'tag') {
    $tag = status_tag_normalize((string) ($rssTag ?? ''));

    if ($tag === '' || status_term_id_exact($tag) < 1) {
        http_response_code(404);
        exit('Feed not found.');
    }

    $rssTitle = '#' . $tag;
    $rssDescription = 'Public posts tagged #' . $tag;
    $rssLink = tag_url($tag);
    $rssItems = public_status_items_by_tag($tag, 50);
} elseif ($rssType === 'author') {
    $author = public_author_find((int) ($rssAuthorId ?? 0));

    if ($author === null) {
        http_response_code(404);
        exit('Feed not found.');
    }

    $authorName = user_display_name($author);
    $rssTitle = $authorName;
    $rssDescription = 'Public posts by ' . $authorName;
    $rssLink = author_url((int) $author['id']);
    $rssItems = public_status_items_by_author_cursor((int) $author['id'], 50);
} else {
    http_response_code(404);
    exit('Feed not found.');
}

$cleanXml = static function (string $value): string {
    $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

    return is_string($clean) ? $clean : '';
};
$xmlEscape = static fn (string $value): string => htmlspecialchars($cleanXml($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
$cdata = static fn (string $value): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $cleanXml($value)) . ']]>';
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
$siteName = site_name();

header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title><?= $xmlEscape($rssTitle . ' | ' . $siteName) ?></title>
        <link><?= $xmlEscape(absolute_url($rssLink)) ?></link>
        <description><?= $cdata($rssDescription) ?></description>
        <language><?= $xmlEscape(str_replace('_', '-', locale())) ?></language>
        <atom:link href="<?= $xmlEscape(absolute_url($requestUri)) ?>" rel="self" type="application/rss+xml" />
<?php foreach ($rssItems as $item):
    $body = trim((string) ($item['body'] ?? ''));
    $plainBody = trim((string) preg_replace('/\s+/u', ' ', $body));
    $itemTitle = $plainBody !== '' ? mb_substr($plainBody, 0, 100) : 'Post';
    if (mb_strlen($plainBody) > 100) {
        $itemTitle .= '…';
    }
    $itemUrl = absolute_url(status_url((int) ($item['id'] ?? 0)));
    $publishedAt = (string) ($item['published_at'] ?? $item['created_at'] ?? '');
    $publishedTimestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
    $publishedDate = $publishedTimestamp !== false ? date(DATE_RSS, $publishedTimestamp) : date(DATE_RSS);
    $imageUrl = status_image_url($item);
    $imageBytes = max(0, (int) ($item['image_bytes'] ?? 0));
    $rssBody = $body !== '' ? $body : status_image_alt_text($item);
?>
        <item>
            <title><?= $xmlEscape($itemTitle) ?></title>
            <link><?= $xmlEscape($itemUrl) ?></link>
            <guid isPermaLink="true"><?= $xmlEscape($itemUrl) ?></guid>
            <pubDate><?= $xmlEscape($publishedDate) ?></pubDate>
            <?php if ($imageUrl !== ''): ?><enclosure url="<?= $xmlEscape(absolute_url($imageUrl)) ?>" length="<?= $imageBytes ?>" type="image/webp" /><?php endif; ?>
            <description><?= $cdata($rssBody) ?></description>
        </item>
<?php endforeach; ?>
    </channel>
</rss>
