<?php
declare(strict_types=1);

namespace TinyCat\Status;

use ValueError;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Metadata
{
    private const USER_AGENT = 'TinyCatLinkPreview/1.0';
    private const HTML_LIMIT = 360000;
    private const OEMBED_LIMIT = 32768;
    private const TIMEOUT = 4;
    private const IMAGE_TIMEOUT = 8;
    private const IMAGE_LIMIT = 5242880;

    /**
     * @return array<mixed>
     * @param array<mixed> $link
     */
    public static function enrich(array $link): array
    {
        return \TinyCat\Http\Client::withHostChecks(static function () use ($link): array {
            $link['_metadata_fetched'] = false;
            $url = (string) ($link['normalized_url'] ?? '');

            if ($url === '') {
                return $link;
            }

            $meta = self::fetch($url);

            if ($meta === []) {
                return $link;
            }

            $link['_metadata_fetched'] = true;

            foreach (['title', 'description', 'provider', 'link_type', 'audio_url'] as $key) {
                if (!empty($meta[$key])) {
                    $link[$key] = (string) $meta[$key];
                }
            }

            if (!empty($meta['image_url'])) {
                $link['image_url'] = (string) $meta['image_url'];
            }

            return $link;
        });
    }

    /**
     * @return array<mixed>
     */
    private static function fetchOembed(string $oembedUrl, string $url): array
    {
        $payload = self::fetchJson($oembedUrl, self::OEMBED_LIMIT);
        if ($payload === []) { return []; }

        $meta = [];
        $title = self::cleanText((string) ($payload['title'] ?? ''), 255);
        if ($title === '' && is_string($payload['html'] ?? null)) {
            $document = self::document($payload['html']);
            $quote = $document?->getElementsByTagName('blockquote')->item(0);
            if ($quote !== null) {
                $paragraph = $quote->getElementsByTagName('p')->item(0);
                $title = self::cleanText($paragraph->textContent ?? $quote->textContent ?? '', 255);
            }
        }
        $description = self::cleanText((string) ($payload['description'] ?? ''), 500);
        if ($description === '' && Providers::classify($url) !== null) {
            $description = self::cleanText((string) ($payload['author_name'] ?? ''), 500);
        }
        $thumbnail = \TinyCat\Http\Client::absoluteUrl($url, (string) ($payload['thumbnail_url'] ?? ''));

        if ($title !== '') {
            $meta['title'] = $title;
        }

        if ($description !== '') {
            $meta['description'] = $description;
        }

        if ($thumbnail !== '' && self::safeUrl($thumbnail)) {
            $meta['image_url'] = $thumbnail;
        }

        return $meta;
    }

    /** @param list<string> $headers
     * @return array<mixed> */
    private static function fetchJson(string $endpoint, int $limit, array $headers = []): array
    {
        $response = self::request($endpoint, $limit, 'application/json', false, self::TIMEOUT, $headers);
        if ($response === null || !self::isJson((string) ($response['content_type'] ?? ''))) { return []; }
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        return is_array($payload) ? $payload : [];
    }

    /** @param array<string, string> $metadata
     * @return array<string, string> */
    private static function cleanMetadata(array $metadata): array
    {
        foreach (['title' => 255, 'description' => 500] as $field => $limit) {
            if (isset($metadata[$field])) { $metadata[$field] = self::cleanText($metadata[$field], $limit); }
        }
        if (!empty($metadata['image_url']) && !self::safeUrl($metadata['image_url'])) { unset($metadata['image_url']); }
        return array_filter($metadata);
    }

    /**
     * @return array<mixed>
     */
    public static function fetch(string $url): array
    {
        return \TinyCat\Http\Client::withHostChecks(static function () use ($url): array {
            $audio = Audio::direct($url);
            if ($audio !== '') { return ['audio_url' => $audio, 'link_type' => 'audio']; }
            $structured = Providers::structuredRequest($url);
            if ($structured !== null) {
                $metadata = self::cleanMetadata(Providers::structuredMetadata(self::fetchJson($structured['url'], self::HTML_LIMIT, $structured['headers']), $url));
                if ($metadata !== []) { return $metadata; }
            }
            $scrape = Providers::scrapeUrl($url);
            if ($scrape !== '') {
                $response = self::request($scrape, self::HTML_LIMIT, 'text/html,application/xhtml+xml', true);
                if ($response === null || !self::isHtml((string) ($response['content_type'] ?? ''))) {
                    return self::cleanMetadata(array_intersect_key(Providers::googleMap($url) ?? [], ['title' => true, 'description' => true]));
                }
                $document = self::document((string) ($response['body'] ?? ''));
                $metadata = $document !== null ? self::cleanMetadata(Providers::scrapedMetadata($document, $url)) : [];
                $fallback = Providers::fallbackScrapeUrl($url);
                if (empty($metadata['image_url']) && $fallback !== '') {
                    $response = self::request($fallback, self::HTML_LIMIT, 'text/html,application/xhtml+xml', true);
                    $document = $response !== null && self::isHtml((string) ($response['content_type'] ?? '')) ? self::document((string) ($response['body'] ?? '')) : null;
                    if ($document !== null) { $metadata = array_replace(self::cleanMetadata(Providers::scrapedMetadata($document, $url)), array_filter($metadata)); }
                }
                return $metadata;
            }
            $rule = Providers::classify($url);
            $wordpress = $rule === null ? Providers::wordpressUrl($url) : '';
            $endpoint = $wordpress !== '' ? '' : Providers::oembedUrl($url);
            if ($rule !== null && $endpoint === '') {
                return ['title' => $rule['title'], 'description' => $rule['description']];
            }
            if (!self::safeUrl($url)) { return []; }
            $metadata = [];
            if ($wordpress !== '') {
                $metadata = self::cleanMetadata(Providers::wordpressMetadata(self::fetchJson($wordpress, self::HTML_LIMIT), $url));
                if (Providers::metadataComplete($metadata)) { return $metadata; }
            } elseif ($endpoint !== '') {
                $metadata = self::fetchOembed($endpoint, $url);
                if ($metadata !== [] && ($rule !== null || Providers::metadataComplete($metadata))) { return $metadata; }
            }
            if ($rule !== null) { return []; }
            $response = self::request($url, self::HTML_LIMIT, 'text/html,application/xhtml+xml', true);

            if ($response === null || !self::isHtml((string) ($response['content_type'] ?? ''))) { return $metadata; }
            $html = self::parseHtml((string) ($response['body'] ?? ''), (string) ($response['url'] ?? $url), $endpoint);
            return array_replace($html, array_filter($metadata));

        });
    }

    public static function isSafeRemoteUrl(string $url): bool
    {
        return self::safeUrl($url);
    }

    /**
     * @return array<mixed>|null
     */
    public static function fetchImage(string $url): ?array
    {
        return \TinyCat\Http\Client::withHostChecks(static function () use ($url): ?array {
            $response = self::request(
                $url,
                self::IMAGE_LIMIT,
                'image/webp,image/jpeg,image/png,image/gif',
                false,
                self::IMAGE_TIMEOUT
            );

            if ($response === null || !str_starts_with(strtolower((string) ($response['content_type'] ?? '')), 'image/')) {
                return null;
            }

            return $response;

        });
    }

    private static function document(string $html): ?\Dom\HTMLDocument
    {
        if ($html === '' || !class_exists(\Dom\HTMLDocument::class)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = \Dom\HTMLDocument::createFromString(
                $html,
                LIBXML_NOERROR | LIBXML_COMPACT
            );
        } catch (ValueError) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $dom;
    }

    /**
     * @return array<mixed>
     */
    private static function parseHtml(string $html, string $baseUrl, string $attemptedEndpoint = ''): array
    {
        $dom = self::document($html);
        if ($dom === null) { return []; }

        $meta = [];
        $candidates = [
            'title' => '',
            'og_title' => '',
            'twitter_title' => '',
            'description' => '',
            'og_description' => '',
            'twitter_description' => '',
            'og_image_secure' => '',
            'og_image' => '',
            'twitter_image' => '',
            'image' => '',
        ];
        $candidates['title'] = self::cleanText($dom->title, 255);

        foreach ($dom->getElementsByTagName('meta') as $node) {
            $name = strtolower(trim((string) ($node->getAttribute('property') ?: $node->getAttribute('name'))));
            $content = trim((string) $node->getAttribute('content'));

            if ($name === '' || $content === '') {
                continue;
            }

            if ($name === 'og:title') {
                $candidates['og_title'] = self::cleanText($content, 255);
            } elseif ($name === 'twitter:title') {
                $candidates['twitter_title'] = self::cleanText($content, 255);
            } elseif ($name === 'description') {
                $candidates['description'] = self::cleanText($content, 500);
            } elseif ($name === 'og:description') {
                $candidates['og_description'] = self::cleanText($content, 500);
            } elseif ($name === 'twitter:description') {
                $candidates['twitter_description'] = self::cleanText($content, 500);
            } elseif (in_array($name, ['og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src', 'image'], true)) {
                $image = \TinyCat\Http\Client::absoluteUrl($baseUrl, $content);

                if ($image !== '' && self::safeUrl($image)) {
                    $key = match ($name) {
                        'og:image:secure_url' => 'og_image_secure',
                        'og:image' => 'og_image',
                        'twitter:image', 'twitter:image:src' => 'twitter_image',
                        default => 'image',
                    };
                    if ($candidates[$key] === '') {
                        $candidates[$key] = $image;
                    }
                }
            }
        }

        foreach ($dom->getElementsByTagName('link') as $node) {
            $rel = strtolower(trim((string) $node->getAttribute('rel')));

            if ($rel !== 'image_src') {
                continue;
            }

            $image = \TinyCat\Http\Client::absoluteUrl($baseUrl, (string) $node->getAttribute('href'));
            if ($image !== '' && self::safeUrl($image)) {
                $candidates['image'] = $image;
                break;
            }
        }

        $meta['title'] = $candidates['title'] ?: $candidates['og_title'] ?: $candidates['twitter_title'];
        $meta['description'] = $candidates['description'] ?: $candidates['og_description'] ?: $candidates['twitter_description'];
        $meta['image_url'] = $candidates['og_image_secure'] ?: $candidates['og_image'] ?: $candidates['twitter_image'] ?: $candidates['image'];
        foreach ($dom->getElementsByTagName('meta') as $node) {
            if (!in_array(strtolower((string) $node->getAttribute('property')), ['og:audio', 'og:audio:url', 'og:audio:secure_url'], true)) { continue; }
            $audio = Audio::remote(\TinyCat\Http\Client::absoluteUrl($baseUrl, (string) $node->getAttribute('content')));
            if ($audio !== '') { $meta['audio_url'] = $audio; $meta['link_type'] = 'audio'; break; }
        }
        if (empty($meta['audio_url'])) {
            foreach ($dom->getElementsByTagName('audio') as $node) {
                $candidate = (string) $node->getAttribute('src');
                if ($candidate === '') { $candidate = (string) $node->getElementsByTagName('source')->item(0)?->getAttribute('src'); }
                $audio = Audio::remote(\TinyCat\Http\Client::absoluteUrl($baseUrl, $candidate));
                if ($audio !== '') { $meta['audio_url'] = $audio; $meta['link_type'] = 'audio'; break; }
            }
        }
        $endpoint = Providers::discover($dom, $baseUrl);
        if (Providers::wordpressUrl($baseUrl) !== '') {
            $meta['provider'] = 'wordpress';
            $meta['link_type'] = 'web';
        }
        if ($endpoint !== '' && $endpoint !== $attemptedEndpoint && Providers::wordpressUrl($baseUrl) === '' && in_array('', $meta, true)) {
            // Keep richer HTML metadata on this first request, notably the article image.
            $meta = array_replace(self::fetchOembed($endpoint, $baseUrl), array_filter($meta));
        }

        return array_filter($meta, static fn (mixed $value): bool => trim((string) $value) !== '');
    }

    /**
     * @return array<mixed>|null
     * @param list<string> $headers
     */
    private static function request(string $url, int $limit, string $accept, bool $allowTruncated = false, int $timeout = self::TIMEOUT, array $headers = []): ?array
    {
        return \TinyCat\Http\Client::get($url, $limit, [
            'accept' => $accept, 'timeout' => $timeout, 'truncate' => $allowTruncated, 'headers' => $headers,
            'policy' => static fn (string $next): bool => self::safeUrl($next) && (!$allowTruncated || Providers::canFetchHtml($next)), 'user_agent' => self::USER_AGENT,
        ]);
    }

    private static function safeUrl(string $url): bool
    {
        return \TinyCat\Http\Client::isSafeUrl($url);
    }

    private static function isHtml(string $contentType): bool
    {
        $contentType = strtolower($contentType);

        return $contentType === '' || str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml');
    }

    private static function isJson(string $contentType): bool
    {
        $contentType = strtolower($contentType);

        return $contentType === '' || str_contains($contentType, 'application/json') || str_contains($contentType, 'text/json');
    }

    private static function cleanText(string $text, int $limit): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) <= $limit) {
            return $text;
        }

        return function_exists('mb_substr')
            ? rtrim((string) mb_substr($text, 0, $limit, 'UTF-8'))
            : rtrim(substr($text, 0, $limit));
    }

}
