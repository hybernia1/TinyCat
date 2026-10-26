<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class Syndication
{
    public static function respond(string $rssType, string $tag = '', int $authorId = 0): void
    {
        $rssTitle = '';
        $rssDescription = '';
        $rssLink = '/';
        $rssItems = [];

        if ($rssType === 'tag') {
            $tag = \TinyCat\Status\Tags::status_tag_normalize($tag);

            $termId = $tag !== '' ? \TinyCat\Status\Tags::status_term_id_exact($tag) : 0;
            if ($termId < 1) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }

            $rssTitle = '#' . $tag;
            $rssDescription = 'Public posts tagged #' . $tag;
            $rssLink = \TinyCat\Status\Tags::tag_url($tag);
            $rssSelfLink = \TinyCat\Status\Tags::tag_feed_url($tag);
            $rssItems = Export::posts(50, null, $termId);
        } elseif ($rssType === 'author') {
            $author = \TinyCat\Users\Repository::public_author_find($authorId);

            if ($author === null) {
                \TinyCat\Http\Document::notFound('Feed not found.');
                return;
            }

            $authorName = \TinyCat\Users\Profiles::user_display_name($author);
            $rssTitle = $authorName;
            $rssDescription = 'Public posts by ' . $authorName;
            $rssLink = \TinyCat\Users\Profiles::author_url((int) $author['id']);
            $rssSelfLink = \TinyCat\Users\Profiles::author_feed_url((int) $author['id']);
            $rssItems = Export::posts(50, (int) $author['id']);
        } else {
            \TinyCat\Http\Document::notFound('Feed not found.');
            return;
        }

        $siteName = \TinyCat\Site\Presentation::site_name();
        $selfUrl = \TinyCat\Site\Presentation::absolute_url($rssSelfLink);
        $locale = \TinyCat\Site\Discovery::locale();
        $items = [];
        foreach ($rssItems as $item) {
            $body = trim((string) ($item['body'] ?? ''));
            $plainBody = trim((string) preg_replace('/\s+/u', ' ', $body));
            $itemTitle = $plainBody !== '' ? mb_substr($plainBody, 0, 100) : 'Post';
            if (mb_strlen($plainBody) > 100) {
                $itemTitle .= '…';
            }
            $itemUrl = \TinyCat\Site\Presentation::absolute_url(\TinyCat\Status\Posts::status_url((int) ($item['id'] ?? 0)));
            $publishedAt = (string) ($item['published_at'] ?? '');
            $publishedTimestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
            $publishedDate = $publishedTimestamp !== false ? date(DATE_RSS, $publishedTimestamp) : '';
            $imageUrl = \TinyCat\Status\Images::status_image_url($item);
            $imageBytes = max(0, (int) ($item['image_bytes'] ?? 0));
            $rssBody = htmlspecialchars($body !== '' ? $body : \TinyCat\Status\Images::status_image_alt_text($item), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $items[] = compact('itemTitle', 'itemUrl', 'publishedDate', 'imageUrl', 'imageBytes', 'rssBody');
        }
        $escape = \TinyCat\Support\Xml::escape(...);
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $body .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
        $body .= '<title>' . $escape($rssTitle . ' | ' . $siteName) . '</title>';
        $body .= '<link>' . $escape(\TinyCat\Site\Presentation::absolute_url($rssLink)) . '</link>';
        $body .= '<description>' . \TinyCat\Support\Xml::cdata($rssDescription) . '</description>';
        $body .= '<language>' . $escape(str_replace('_', '-', $locale)) . '</language>';
        $body .= '<atom:link href="' . $escape($selfUrl) . '" rel="self" type="application/rss+xml" />';
        foreach ($items as $item) {
            $body .= '<item><title>' . $escape($item['itemTitle']) . '</title><link>' . $escape($item['itemUrl']) . '</link>';
            $body .= '<guid isPermaLink="true">' . $escape($item['itemUrl']) . '</guid>';
            if ($item['publishedDate'] !== '') { $body .= '<pubDate>' . $escape($item['publishedDate']) . '</pubDate>'; }
            if ($item['imageUrl'] !== '') {
                $body .= '<enclosure url="' . $escape(\TinyCat\Site\Presentation::absolute_url($item['imageUrl'])) . '" length="' . $item['imageBytes'] . '" type="image/webp" />';
            }
            $body .= '<description>' . \TinyCat\Support\Xml::cdata($item['rssBody']) . '</description></item>';
        }
        $body .= "</channel></rss>\n";
        \TinyCat\Http\Document::respond($body, 'application/rss+xml', 300);
    }
}
