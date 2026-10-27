<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use SimpleXMLElement;
use TinyCat\Http\Client;

/** RSS/Atom normalization, independent of import history and post storage. */
final class FeedParser
{
    private const ATOM = 'http://www.w3.org/2005/Atom';
    private const MEDIA = 'http://search.yahoo.com/mrss/';
    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const SEZNAM = 'https://www.seznam.cz';

    /** @return list<array<string, mixed>> */
    public static function parse(string $xml, string $baseUrl = ''): array
    {
        return Client::withHostChecks(static fn (): array => self::items($xml, $baseUrl));
    }

    /** @return list<array<string, mixed>> */
    private static function items(string $xml, string $baseUrl): array
    {
        if ($xml === '' || strlen($xml) > 2097152 || stripos($xml, '<!DOCTYPE') !== false || !function_exists('simplexml_load_string')) { return []; }
        $previous = libxml_use_internal_errors(true);
        try { $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$feed instanceof SimpleXMLElement) { return []; }
        $atom = $feed->getName() === 'feed';
        if (!$atom && ($feed->getName() !== 'rss' || !isset($feed->channel))) { return []; }
        $channel = $atom ? $feed : $feed->channel;
        $baseUrl = self::base($baseUrl, $feed);
        if (!$atom) { $baseUrl = self::base($baseUrl, $channel); }
        $nodes = $atom ? $feed->children(self::ATOM)->entry : $channel->item;
        $items = [];
        $seen = 0;
        foreach ($nodes as $node) {
            if (++$seen > 100) { break; }
            $entry = $atom ? $node->children(self::ATOM) : $node;
            $base = self::base($baseUrl, $node);
            $link = '';
            if ($atom) {
                foreach ($entry->link as $linkNode) {
                    $rel = strtolower((string) ($linkNode->attributes()['rel'] ?? 'alternate'));
                    if ($rel === '' || $rel === 'alternate') {
                        $link = Client::absoluteUrl(self::base($base, $linkNode), (string) $linkNode->attributes()['href']);
                        break;
                    }
                }
            } else { $link = Client::absoluteUrl($base, trim((string) $entry->link)); }
            $seznam = $node->children(self::SEZNAM);
            $creator = trim((string) $node->children('http://purl.org/dc/elements/1.1/')->creator);
            if ($creator === '') { $creator = trim((string) ($entry->author->name ?? $entry->author)); }
            if ($creator === '') {
                $authors = [];
                foreach (($seznam->authors->children('')->value ?? []) as $value) { $authors[] = (string) $value; }
                $creator = implode(', ', $authors);
            }
            $categories = [];
            foreach ($entry->category as $category) {
                $value = self::text((string) ($category->attributes()['term'] ?? $category), 200);
                if ($value !== '') { $categories[] = $value; }
            }
            foreach (($seznam->sections->children('')->value ?? []) as $value) {
                $value = self::text((string) $value, 200);
                if ($value !== '') { $categories[] = $value; }
            }
            $title = self::text((string) $entry->title, 500);
            $encoded = (string) $node->children(self::CONTENT)->encoded;
            $description = (string) ($entry->description ?: $entry->summary);
            $content = isset($entry->content) ? (string) $entry->content : '';
            if ($atom && (string) ($entry->content->attributes()['type'] ?? '') === 'xhtml') { $content = $entry->content->asXML() ?: ''; }
            if ($atom && (string) ($entry->summary->attributes()['type'] ?? '') === 'xhtml') { $description = $entry->summary->asXML() ?: ''; }
            $descriptionSource = $description !== '' ? $description : ($encoded !== '' ? $encoded : $content);
            $guid = trim((string) ($entry->guid ?: $entry->id));
            if ($guid === '') { $guid = $link; }
            if ($guid === '' || ($title === '' && $link === '')) { continue; }
            $published = trim((string) ($entry->pubDate ?: $entry->published ?: $entry->updated));
            $timestamp = $published !== '' ? strtotime($published) : false;
            $images = self::images($node, $base, [$description, $encoded, $content]);
            $items[] = [
                'guid' => $guid, 'title' => $title, 'description' => self::descriptionText($descriptionSource, 1200),
                'url' => Client::isSafeUrl($link) ? $link : '',
                'image_url' => $images[0] ?? '', 'image_urls' => $images,
                'author' => self::text($creator, 200), 'categories' => array_values(array_unique($categories)),
                'published_at' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null,
                '_timestamp' => $timestamp !== false ? $timestamp : 0,
            ];
        }
        usort($items, static fn (array $a, array $b): int => $a['_timestamp'] <=> $b['_timestamp']);
        return $items;
    }

    private static function base(string $base, SimpleXMLElement $node): string
    {
        $value = (string) $node->attributes('http://www.w3.org/XML/1998/namespace')['base'];
        return $value !== '' ? Client::absoluteUrl($base, $value) : $base;
    }

    /** @param list<string> $html
     * @return list<string> */
    private static function images(SimpleXMLElement $node, string $base, array $html): array
    {
        $candidates = [];
        $add = static function (string $url, string $imageBase) use (&$candidates): void {
            $url = Client::absoluteUrl($imageBase, html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url !== '' && strlen($url) <= 2048 && count($candidates) < 8 && !in_array($url, $candidates, true) && Client::isSafeUrl($url)) { $candidates[] = $url; }
        };
        $media = $node->children(self::MEDIA);
        $containers = [[$media, $base]];
        foreach ($media->group as $group) { $containers[] = [$group->children(self::MEDIA), self::base($base, $group)]; }
        foreach ($containers as [$container, $containerBase]) {
            foreach (['content', 'thumbnail'] as $element) {
                foreach ($container->{$element} as $image) {
                    $type = strtolower((string) $image->attributes()['type']);
                    $medium = strtolower((string) $image->attributes()['medium']);
                    if (($type !== '' && !str_starts_with($type, 'image/')) || ($medium !== '' && $medium !== 'image')) { continue; }
                    $add((string) $image->attributes()['url'], self::base($containerBase, $image));
                }
            }
        }
        foreach ($node->children(self::SEZNAM)->image as $image) {
            $add((string) $image->children(self::SEZNAM)->url, self::base($base, $image));
        }
        foreach ($node->enclosure as $image) {
            $type = strtolower((string) $image->attributes()['type']);
            if ($type === '' || str_starts_with($type, 'image/')) { $add((string) $image->attributes()['url'], self::base($base, $image)); }
        }
        foreach ($node->children(self::ATOM)->link as $image) {
            if ((string) $image->attributes()['rel'] === 'enclosure' && str_starts_with(strtolower((string) $image->attributes()['type']), 'image/')) {
                $add((string) $image->attributes()['href'], self::base($base, $image));
            }
        }
        foreach ($html as $source) {
            if ($source === '' || !class_exists(\Dom\HTMLDocument::class)) { continue; }
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = \Dom\HTMLDocument::createFromString($source, LIBXML_NOERROR | LIBXML_COMPACT);
                foreach ($dom->getElementsByTagName('img') as $image) {
                    $add($image->getAttribute('src') ?? '', $base);
                    $add($image->getAttribute('data-src') ?? '', $base);
                }
            } catch (\ValueError) { continue; }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        }
        return $candidates;
    }
    public static function text(string $value, int $limit): string
    {
        $value = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    public static function descriptionText(string $value, int $limit): string
    {
        $value = preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a\s*>~is',
            static function (array $match): string {
                $label = (string) $match[2];
                $videoUrl = self::feedHtmlVideoUrl((string) $match[1], 'href');
                if ($videoUrl === '') {
                    return $label;
                }

                $labelText = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                foreach (\TinyCat\Status\LinkParser::extract($labelText) as $labelLink) {
                    if ((string) ($labelLink['normalized_url'] ?? '') === $videoUrl) {
                        return $label;
                    }
                }

                return $label . ' ' . $videoUrl;
            },
            $value
        ) ?? $value;
        $value = preg_replace_callback(
            '~<(?:iframe|embed)\b([^>]*)>(?:\s*</iframe\s*>)?~is',
            static fn (array $match): string => self::feedHtmlVideoUrl((string) $match[1], 'src'),
            $value
        ) ?? $value;
        $value = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace_callback(
            \TinyCat\Status\LinkParser::pattern(),
            static function (array $match): string {
                [$url, $tail] = \TinyCat\Status\LinkParser::splitTail((string) ($match[0] ?? ''));
                $videoUrl = self::supportedVideoUrl($url);
                return ($videoUrl !== '' ? $videoUrl : '') . $tail;
            },
            $value
        ) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = preg_replace('/\s+([.,;:!?])/u', '$1', $value) ?? $value;
        $value = preg_replace('/(?:^|\s)The post\b.*?\bappeared first on\s*[.!?]*\s*$/iu', '', $value) ?? $value;
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function feedHtmlVideoUrl(string $attributes, string $name): string
    {
        $name = preg_quote($name, '~');
        if (preg_match('~\b' . $name . '\s*=\s*(["\'])(.*?)\1~is', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('~\b' . $name . '\s*=\s*([^\s>]+)~i', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode(trim((string) $match[1], "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private static function supportedVideoUrl(string $url): string
    {
        $link = \TinyCat\Status\LinkParser::fromRaw(trim($url));
        return ($link['link_type'] ?? '') === 'video' ? (string) ($link['normalized_url'] ?? '') : '';
    }

}
