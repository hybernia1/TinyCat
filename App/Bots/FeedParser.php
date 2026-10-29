<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use SimpleXMLElement;
use TinyCat\Http\Client;

/** Syndication normalization, independent of import history and post storage. */
final class FeedParser
{
    private const ATOM = 'http://www.w3.org/2005/Atom';
    private const MEDIA = 'http://search.yahoo.com/mrss/';
    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const SEZNAM = 'https://www.seznam.cz';

    /** @return list<array<string, mixed>> */
    public static function fetch(string $url): array
    {
        $document = self::inspect($url);
        return $document['items'];
    }

    /** @return array{format: string, url: string, title: string, items: list<array<string, mixed>>, sources: list<array<string, string>>} */
    public static function inspect(string $url): array
    {
        $response = Client::get($url, 2097152, [
            'accept' => 'application/feed+json,application/rss+xml,application/atom+xml,application/xml,text/xml,text/html;q=0.5', 'timeout' => 10,
        ]);
        if ($response === null) { throw new \RuntimeException('Feed could not be downloaded.'); }
        return self::document($response['body'], $response['url']);
    }

    /** @return array{format: string, url: string, title: string, items: list<array<string, mixed>>, sources: list<array<string, string>>} */
    public static function document(string $body, string $url = ''): array
    {
        return Client::withHostChecks(static function () use ($body, $url): array {
            $format = 'unknown'; $title = ''; $sources = [];
            if (strlen($body) <= 2097152 && str_starts_with(ltrim($body, "\xEF\xBB\xBF \r\n\t"), '{')) {
                $json = json_decode(ltrim($body, "\xEF\xBB\xBF"), true, 32);
                if (is_array($json) && in_array($json['version'] ?? '', ['https://jsonfeed.org/version/1', 'https://jsonfeed.org/version/1.1'], true)) {
                    $format = $json['version'] === 'https://jsonfeed.org/version/1.1' ? 'json_feed_1_1' : 'json_feed_1';
                    $title = self::text(self::string($json['title'] ?? ''), 120);
                }
            } elseif (strlen($body) <= 2097152 && stripos($body, '<!DOCTYPE') === false && function_exists('simplexml_load_string')) {
                $previous = libxml_use_internal_errors(true);
                try { $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA); }
                finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
                if ($xml instanceof SimpleXMLElement) {
                    $format = match ($xml->getName()) { 'rss' => 'rss', 'feed' => 'atom', 'RDF' => 'rss_1', default => 'unknown' };
                    $title = self::text((string) ($xml->channel->title ?? $xml->title ?? $xml->head->title), 120);
                }
            }
            if ($format === 'unknown') { $sources = self::discovery($body, $url); if ($sources !== []) { $format = 'discovery'; } }
            return ['format' => $format, 'url' => $url, 'title' => $title, 'items' => self::parse($body, $url), 'sources' => $sources];
        });
    }

    /** @return list<array<string, mixed>> */
    public static function parse(string $xml, string $baseUrl = ''): array
    {
        return Client::withHostChecks(static fn (): array => str_starts_with(ltrim($xml, "\xEF\xBB\xBF \r\n\t"), '{') ? self::jsonItems($xml, $baseUrl) : self::items($xml, $baseUrl));
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
        $rdf = $feed->getName() === 'RDF' && isset($feed->children('http://purl.org/rss/1.0/')->channel);
        if (!$atom && !$rdf && ($feed->getName() !== 'rss' || !isset($feed->channel))) { return []; }
        $channel = $atom ? $feed : ($rdf ? $feed->children('http://purl.org/rss/1.0/')->channel : $feed->channel);
        $baseUrl = self::base($baseUrl, $feed);
        if (!$atom) { $baseUrl = self::base($baseUrl, $channel); }
        $generator = $atom ? (string) ($channel->children(self::ATOM)->generator->attributes()['uri'] ?? '') : (string) $channel->generator;
        $home = $atom ? '' : Client::absoluteUrl($baseUrl, (string) $channel->link);
        if ($atom) {
            foreach ($channel->children(self::ATOM)->link as $siteLink) {
                if ((string) ($siteLink['rel'] ?? 'alternate') === 'alternate') { $home = Client::absoluteUrl($baseUrl, (string) $siteLink['href']); break; }
            }
        }
        $wordpressRoot = WordPressFeed::hint($generator, $home, $baseUrl);
        $nodes = $atom ? $feed->children(self::ATOM)->entry : ($rdf ? $feed->children('http://purl.org/rss/1.0/')->item : $channel->item);
        $items = [];
        $seen = 0;
        foreach ($nodes as $node) {
            if (++$seen > 100) { break; }
            $entry = $atom ? $node->children(self::ATOM) : ($rdf ? $node->children('http://purl.org/rss/1.0/') : $node);
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
            if ($rdf) { $guid = trim((string) $node->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#')['about']); }
            $images = self::images($node, $base, [$description, $encoded, $content]);
            if ($guid === '') { $guid = $link; }
            if ($guid === '') { $guid = $images[0] ?? ''; }
            if ($guid === '' || ($title === '' && $link === '' && $images === [])) { continue; }
            $published = trim((string) ($entry->pubDate ?: $entry->published ?: $entry->updated ?: $node->children('http://purl.org/dc/elements/1.1/')->date));
            $timestamp = self::timestamp($published);
            $items[] = [
                'guid' => $guid, 'title' => $title, 'description' => self::descriptionText($descriptionSource, 1200),
                'url' => Client::isSafeUrl($link) ? $link : '',
                'image_url' => $images[0] ?? '', 'image_urls' => $images,
                'author' => self::text($creator, 200), 'categories' => array_values(array_unique($categories)),
                'published_at' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null,
                '_timestamp' => $timestamp !== false ? $timestamp : 0,
                '_wordpress_root' => $wordpressRoot,
            ];
        }
        usort($items, static fn (array $a, array $b): int => $a['_timestamp'] <=> $b['_timestamp']);
        return $items;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function timestamp(string $value): int|false
    {
        return $value !== '' && strlen($value) <= 100 && !str_contains($value, "\0") ? strtotime($value) : false;
    }

    /** @return list<array<string, mixed>> */
    private static function jsonItems(string $body, string $base): array
    {
        if (strlen($body) > 2097152) { return []; }
        $feed = json_decode(ltrim($body, "\xEF\xBB\xBF"), true, 32);
        if (!is_array($feed) || !in_array($feed['version'] ?? '', ['https://jsonfeed.org/version/1', 'https://jsonfeed.org/version/1.1'], true)
            || !is_string($feed['title'] ?? null) || !is_array($feed['items'] ?? null) || !array_is_list($feed['items'])) { return []; }
        $items = [];
        foreach (array_slice($feed['items'], 0, 100) as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '' || strlen($item['id']) > 8192
                || (!is_string($item['content_text'] ?? null) && !is_string($item['content_html'] ?? null))) { continue; }
            $html = self::string($item['content_html'] ?? '');
            $urls = [self::string($item['image'] ?? ''), self::string($item['banner_image'] ?? '')];
            foreach (array_slice(is_array($item['attachments'] ?? null) ? $item['attachments'] : [], 0, 8) as $attachment) {
                if (is_array($attachment) && str_starts_with(self::string($attachment['mime_type'] ?? ''), 'image/')) { $urls[] = self::string($attachment['url'] ?? ''); }
            }
            $images = [];
            $embeddedImages = class_exists(SimpleXMLElement::class) ? self::images(new SimpleXMLElement('<item/>'), $base, [$html]) : [];
            foreach (array_merge($urls, $embeddedImages) as $url) {
                $url = Client::absoluteUrl($base, $url);
                if ($url !== '' && strlen($url) <= 2048 && count($images) < 8 && Client::isSafeUrl($url) && !in_array($url, $images, true)) { $images[] = $url; }
            }
            $url = Client::absoluteUrl($base, self::string($item['url'] ?? $item['external_url'] ?? ''));
            $authors = $item['authors'] ?? (isset($item['author']) ? [$item['author']] : ($feed['authors'] ?? [$feed['author'] ?? []]));
            $names = [];
            foreach (array_slice(is_array($authors) ? $authors : [], 0, 10) as $author) {
                if (is_array($author)) { $names[] = self::text(self::string($author['name'] ?? ''), 200); }
            }
            $tags = array_slice(is_array($item['tags'] ?? null) ? $item['tags'] : [], 0, 50);
            $tags = array_values(array_unique(array_filter(array_map(static fn (mixed $tag): string => self::text(self::string($tag), 200), $tags))));
            $date = self::string($item['date_published'] ?? $item['date_modified'] ?? '');
            $timestamp = self::timestamp($date);
            $description = self::string($item['summary'] ?? $item['content_text'] ?? $html);
            $items[] = ['guid' => $item['id'], 'title' => self::text(self::string($item['title'] ?? ''), 500),
                'description' => self::descriptionText($description, 1200), 'url' => Client::isSafeUrl($url) ? $url : '',
                'author' => self::text(implode(', ', array_filter($names)), 200), 'categories' => $tags,
                'image_url' => $images[0] ?? '', 'image_urls' => $images,
                'published_at' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null, '_timestamp' => $timestamp !== false ? $timestamp : 0];
        }
        usort($items, static fn (array $a, array $b): int => $a['_timestamp'] <=> $b['_timestamp']);
        return $items;
    }

    /** @return list<array<string, string>> */
    private static function discovery(string $body, string $base): array
    {
        if ($base === '' || strlen($body) > 2097152 || !class_exists(\Dom\HTMLDocument::class)) { return []; }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = \Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR | LIBXML_COMPACT);
            $baseNode = $dom->getElementsByTagName('base')->item(0);
            if ($baseNode !== null) {
                $declaredBase = Client::absoluteUrl($base, $baseNode->getAttribute('href') ?? '');
                if (Client::isSafeUrl($declaredBase)) { $base = $declaredBase; }
            }
            $sources = [];
            foreach ($dom->getElementsByTagName('link') as $link) {
                $rels = preg_split('/\s+/', strtolower($link->getAttribute('rel') ?? '')) ?: [];
                $type = strtolower(trim(explode(';', $link->getAttribute('type') ?? '')[0]));
                if (!in_array('alternate', $rels, true) || !in_array($type, ['application/rss+xml', 'application/atom+xml', 'application/feed+json'], true)) { continue; }
                $url = Client::absoluteUrl($base, $link->getAttribute('href') ?? '');
                if ($url === '' || strlen($url) > 2048 || !Client::isSafeUrl($url)) { continue; }
                $sources[$url] = ['name' => self::text($link->getAttribute('title') ?? '', 120) ?: $url, 'feed_url' => $url];
                if (count($sources) >= 8) { break; }
            }
            return array_values($sources);
        } catch (\ValueError) { return []; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
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
