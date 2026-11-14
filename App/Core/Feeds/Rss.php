<?php
declare(strict_types=1);
namespace TinyCat\Core\Feeds;

use TinyCat\Core\Text\LinkParser;

/** RSS serialization knows no publication kinds or domain tables. */
final class Rss
{
    /** @param list<array<string, mixed>> $items */
    public static function render(string $rssTitle, string $rssDescription, string $rssLink, string $selfUrl, array $items, string $rssImage = '', string $locale = 'en'): string
    {
        $siteName = \TinyCat\Core\Ui\Site::site_name();
        $escape = \TinyCat\Support\Xml::escape(...);
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $body .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/"><channel>';
        $body .= '<title>' . $escape($rssTitle . ' | ' . $siteName) . '</title>';
        $body .= '<link>' . $escape(\TinyCat\Core\Ui\Site::absolute_url($rssLink)) . '</link>';
        $body .= '<description>' . \TinyCat\Support\Xml::cdata($rssDescription) . '</description>';
        $body .= '<language>' . $escape(str_replace('_', '-', $locale)) . '</language>';
        $body .= '<atom:link href="' . $escape($selfUrl) . '" rel="self" type="application/rss+xml" />';
        if ($rssImage !== '') {
            $body .= '<image><url>' . $escape(\TinyCat\Core\Ui\Site::absolute_url($rssImage)) . '</url>'
                . '<title>' . $escape($rssTitle . ' | ' . $siteName) . '</title>'
                . '<link>' . $escape(\TinyCat\Core\Ui\Site::absolute_url($rssLink)) . '</link></image>';
        }
        foreach ($items as $item) {
            $body .= '<item><title>' . $escape($item['itemTitle']) . '</title><link>' . $escape($item['itemUrl']) . '</link>';
            $body .= '<guid isPermaLink="true">' . $escape($item['itemUrl']) . '</guid>';
            if ($item['publishedDate'] !== '') { $body .= '<pubDate>' . $escape($item['publishedDate']) . '</pubDate>'; }
            $body .= '<dc:creator>' . $escape($item['creator']) . '</dc:creator>';
            $body .= '<atom:author><atom:name>' . $escape($item['creator']) . '</atom:name>' . ($item['creatorUrl'] !== '' ? '<atom:uri>' . $escape((string) $item['creatorUrl']) . '</atom:uri>' : '') . '</atom:author>';
            foreach ($item['categories'] as $category) {
                $body .= '<category domain="' . $escape(\TinyCat\Core\Ui\Site::absolute_url((string) $category['url'])) . '">' . $escape((string) $category['name']) . '</category>';
            }
            if ($item['imageUrl'] !== '') {
                $body .= '<media:content medium="image"' . ($item['imageType'] !== '' ? ' type="' . $escape($item['imageType']) . '"' : '')
                    . ' url="' . $escape(\TinyCat\Core\Ui\Site::absolute_url($item['imageUrl'])) . '" />';
            }
            if ($item['attachedImage'] !== '') {
                $body .= '<enclosure url="' . $escape(\TinyCat\Core\Ui\Site::absolute_url($item['imageUrl'])) . '" length="' . $item['imageBytes'] . '" type="image/webp" />';
            }
            $body .= '<description>' . \TinyCat\Support\Xml::cdata($item['rssBody']) . '</description></item>';
        }
        $body .= "</channel></rss>\n";
        return $body;
    }

    /** Render only escaped prose and HTTP links; RSS consumers do not run our UI. */
    public static function html(string $text): string
    {
        $escape = \TinyCat\Support\Html::e(...);
        preg_match_all(LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE);
        $html = '';
        $offset = 0;
        foreach ($matches[0] as [$raw, $position]) {
            $html .= $escape(substr($text, $offset, $position - $offset));
            [$url, $tail] = LinkParser::splitTail($raw);
            $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
            $html .= '<a href="' . $escape($href) . '">' . $escape($url) . '</a>' . $escape($tail);
            $offset = $position + strlen($raw);
        }
        $html .= $escape(substr($text, $offset));
        return $html !== '' ? '<p>' . nl2br($html, false) . '</p>' : '';
    }
}
