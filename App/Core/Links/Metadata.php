<?php
declare(strict_types=1);

namespace TinyCat\Core\Links;

use ValueError;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

class Metadata
{
    protected const USER_AGENT = 'TinyCatLinkPreview/1.0';
    protected const HTML_LIMIT = 360000;
    protected const OEMBED_LIMIT = 32768;
    protected const TIMEOUT = 4;
    protected const IMAGE_TIMEOUT = 8;
    protected const IMAGE_LIMIT = 5242880;

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

            $meta = static::fetch($url);

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
    protected static function fetchOembed(string $oembedUrl, string $url): array
    {
        $payload = static::fetchJson($oembedUrl, static::OEMBED_LIMIT);
        if ($payload === []) { return []; }

        $meta = [];
        $title = static::cleanText((string) ($payload['title'] ?? ''), 255);
        if ($title === '' && is_string($payload['html'] ?? null)) {
            $document = static::document($payload['html']);
            $quote = $document?->getElementsByTagName('blockquote')->item(0);
            if ($quote !== null) {
                $paragraph = $quote->getElementsByTagName('p')->item(0);
                $title = static::cleanText($paragraph->textContent ?? $quote->textContent ?? '', 255);
            }
        }
        $description = static::cleanText((string) ($payload['description'] ?? ''), 500);
        if ($description === '' && static::useAuthorDescription($url)) {
            $description = static::cleanText((string) ($payload['author_name'] ?? ''), 500);
        }
        $thumbnail = \TinyCat\Http\Client::absoluteUrl($url, (string) ($payload['thumbnail_url'] ?? ''));

        if ($title !== '') {
            $meta['title'] = $title;
        }

        if ($description !== '') {
            $meta['description'] = $description;
        }

        if ($thumbnail !== '' && static::safeUrl($thumbnail)) {
            $meta['image_url'] = $thumbnail;
        }

        return $meta;
    }

    /** @param list<string> $headers
     * @return array<mixed> */
    protected static function fetchJson(string $endpoint, int $limit, array $headers = []): array
    {
        $response = static::request($endpoint, $limit, 'application/json', false, static::TIMEOUT, $headers);
        if ($response === null || !static::isJson((string) ($response['content_type'] ?? ''))) { return []; }
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        return is_array($payload) ? $payload : [];
    }

    /** @param array<string, string> $metadata
     * @return array<string, string> */
    protected static function cleanMetadata(array $metadata): array
    {
        foreach (['title' => 255, 'description' => 500] as $field => $limit) {
            if (isset($metadata[$field])) { $metadata[$field] = static::cleanText($metadata[$field], $limit); }
        }
        if (!empty($metadata['image_url']) && !static::safeUrl($metadata['image_url'])) { unset($metadata['image_url']); }
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
            $response = static::request($url, static::HTML_LIMIT, 'text/html,application/xhtml+xml', true);
            return $response !== null && static::isHtml((string) ($response['content_type'] ?? ''))
                ? static::parseHtml((string) ($response['body'] ?? ''), (string) ($response['url'] ?? $url)) : [];
        });
    }

    public static function isSafeRemoteUrl(string $url): bool
    {
        return static::safeUrl($url);
    }

    /**
     * @return array<mixed>|null
     */
    public static function fetchImage(string $url): ?array
    {
        return \TinyCat\Http\Client::withHostChecks(static function () use ($url): ?array {
            $response = static::request(
                $url,
                static::IMAGE_LIMIT,
                'image/webp,image/jpeg,image/png,image/gif',
                false,
                static::IMAGE_TIMEOUT
            );

            if ($response === null || !str_starts_with(strtolower((string) ($response['content_type'] ?? '')), 'image/')) {
                return null;
            }

            return $response;

        });
    }

    protected static function document(string $html): ?\Dom\HTMLDocument
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
    protected static function parseHtml(string $html, string $baseUrl, string $attemptedEndpoint = ''): array
    {
        $dom = static::document($html);
        if ($dom === null) { return []; }

        return static::parseDocument($dom, $baseUrl, $attemptedEndpoint);
    }

    /** @return array<mixed> */
    protected static function parseDocument(\Dom\HTMLDocument $dom, string $baseUrl, string $attemptedEndpoint = ''): array
    {
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
        $candidates['title'] = static::cleanText($dom->title, 255);

        foreach ($dom->getElementsByTagName('meta') as $node) {
            $name = strtolower(trim((string) ($node->getAttribute('property') ?: $node->getAttribute('name'))));
            $content = trim((string) $node->getAttribute('content'));

            if ($name === '' || $content === '') {
                continue;
            }

            if ($name === 'og:title') {
                $candidates['og_title'] = static::cleanText($content, 255);
            } elseif ($name === 'twitter:title') {
                $candidates['twitter_title'] = static::cleanText($content, 255);
            } elseif ($name === 'description') {
                $candidates['description'] = static::cleanText($content, 500);
            } elseif ($name === 'og:description') {
                $candidates['og_description'] = static::cleanText($content, 500);
            } elseif ($name === 'twitter:description') {
                $candidates['twitter_description'] = static::cleanText($content, 500);
            } elseif (in_array($name, ['og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src', 'image'], true)) {
                $image = \TinyCat\Http\Client::absoluteUrl($baseUrl, $content);

                if ($image !== '' && static::safeUrl($image)) {
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
            if ($image !== '' && static::safeUrl($image)) {
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
        return array_filter($meta, static fn (mixed $value): bool => trim((string) $value) !== '');
    }

    /**
     * @return array<mixed>|null
     * @param list<string> $headers
     */
    protected static function request(string $url, int $limit, string $accept, bool $allowTruncated = false, int $timeout = self::TIMEOUT, array $headers = []): ?array
    {
        return \TinyCat\Http\Client::get($url, $limit, [
            'accept' => $accept, 'timeout' => $timeout, 'truncate' => $allowTruncated, 'headers' => $headers,
            'policy' => static fn (string $next): bool => static::safeUrl($next), 'user_agent' => static::USER_AGENT,
        ]);
    }

    protected static function safeUrl(string $url): bool
    {
        return \TinyCat\Http\Client::isSafeUrl($url);
    }

    protected static function isHtml(string $contentType): bool
    {
        $contentType = strtolower($contentType);

        return $contentType === '' || str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml');
    }

    protected static function isJson(string $contentType): bool
    {
        $contentType = strtolower($contentType);

        return $contentType === '' || str_contains($contentType, 'application/json') || str_contains($contentType, 'text/json');
    }

    protected static function cleanText(string $text, int $limit): string
    {
        return \TinyCat\Core\Text\Text::htmlText($text, $limit, encodedMarkup: false);
    }

    protected static function useAuthorDescription(string $url): bool { return false; }
}
