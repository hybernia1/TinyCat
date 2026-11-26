<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Core\Text\LinkParser;

/** RSS serialization knows no publication kinds or domain tables. */
final class Rss
{
    /** @param list<array<string, mixed>> $items */
    public static function render(string $rssTitle, string $rssDescription, string $rssLink, string $selfUrl, array $items, string $rssImage = '', string $locale = 'en'): string
    {
        $escape = \TinyCat\Support\Xml::escape(...);
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $body .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/"><channel>';
        $body .= '<title>' . $escape($rssTitle) . '</title>';
        $body .= '<link>' . $escape($rssLink) . '</link>';
        $body .= '<description>' . \TinyCat\Support\Xml::cdata($rssDescription) . '</description>';
        $body .= '<language>' . $escape(str_replace('_', '-', $locale)) . '</language>';
        $body .= '<atom:link href="' . $escape($selfUrl) . '" rel="self" type="application/rss+xml" />';
        if ($rssImage !== '') {
            $body .= '<image><url>' . $escape($rssImage) . '</url>'
                . '<title>' . $escape($rssTitle) . '</title>'
                . '<link>' . $escape($rssLink) . '</link></image>';
        }
        foreach ($items as $item) {
            $item += ['published_date' => '', 'creator' => '', 'creator_url' => '', 'categories' => [], 'image_url' => '', 'image_type' => '', 'html' => ''];
            $body .= '<item><title>' . $escape($item['title']) . '</title><link>' . $escape($item['url']) . '</link>';
            $body .= '<guid isPermaLink="true">' . $escape($item['url']) . '</guid>';
            if ($item['published_date'] !== '') { $body .= '<pubDate>' . $escape($item['published_date']) . '</pubDate>'; }
            $body .= '<dc:creator>' . $escape($item['creator']) . '</dc:creator>';
            $body .= '<atom:author><atom:name>' . $escape($item['creator']) . '</atom:name>' . ($item['creator_url'] !== '' ? '<atom:uri>' . $escape((string) $item['creator_url']) . '</atom:uri>' : '') . '</atom:author>';
            foreach ($item['categories'] as $category) {
                $body .= '<category domain="' . $escape((string) $category['url']) . '">' . $escape((string) $category['name']) . '</category>';
            }
            if ($item['image_url'] !== '') {
                $body .= '<media:content medium="image"' . ($item['image_type'] !== '' ? ' type="' . $escape($item['image_type']) . '"' : '')
                    . ' url="' . $escape($item['image_url']) . '" />';
            }
            if (is_array($item['enclosure'] ?? null)) {
                $enclosure = $item['enclosure'];
                $body .= '<enclosure url="' . $escape((string) $enclosure['url']) . '" length="' . max(0, (int) $enclosure['length']) . '" type="' . $escape((string) $enclosure['type']) . '" />';
            }
            $body .= '<description>' . \TinyCat\Support\Xml::cdata($item['html']) . '</description></item>';
        }
        $body .= "</channel></rss>\n";
        return $body;
    }

    /** Owner supplies final titles, absolute URLs and authorized items.
     * @param list<array<string, mixed>> $items */
    public static function respond(string $title, string $description, string $url, string $selfUrl, array $items, string $imageUrl = '', string $locale = 'en'): void
    {
        \TinyCat\Http\Document::respond(self::render($title, $description, $url, $selfUrl, $items, $imageUrl, $locale), 'application/rss+xml');
    }

    /** Render only escaped prose and HTTP links; RSS consumers do not run our UI. */
    public static function html(string $text): string
    {
        $escape = \TinyCat\Core\Ui\Html::e(...);
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
