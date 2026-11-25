<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

use TinyCat\Core\Links\Audio;

/** Social provider policy over the shared HTTP and metadata parser. */
final class LinkMetadata extends \TinyCat\Core\Links\Metadata
{
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

    protected static function parseHtml(string $html, string $baseUrl, string $attemptedEndpoint = ''): array
    {
        $meta = parent::parseHtml($html, $baseUrl, $attemptedEndpoint);
        $dom = self::document($html);
        if ($dom === null) { return $meta; }
        $endpoint = Providers::discover($dom, $baseUrl);
        if (Providers::wordpressUrl($baseUrl) !== '') {
            $meta['provider'] = 'wordpress';
            $meta['link_type'] = 'web';
        }
        if ($endpoint !== '' && $endpoint !== $attemptedEndpoint && Providers::wordpressUrl($baseUrl) === '' && count(array_intersect_key($meta, ['title' => true, 'description' => true, 'image_url' => true])) < 3) {
            // Keep richer HTML metadata on this first request, notably the article image.
            $meta = array_replace(self::fetchOembed($endpoint, $baseUrl), array_filter($meta));
        }

        return array_filter($meta, static fn (mixed $value): bool => trim((string) $value) !== '');
    }

    protected static function request(string $url, int $limit, string $accept, bool $allowTruncated = false, int $timeout = self::TIMEOUT, array $headers = []): ?array
    {
        return \TinyCat\Http\Client::get($url, $limit, [
            'accept' => $accept, 'timeout' => $timeout, 'truncate' => $allowTruncated, 'headers' => $headers,
            'policy' => static fn (string $next): bool => self::safeUrl($next) && (!$allowTruncated || Providers::canFetchHtml($next)), 'user_agent' => self::USER_AGENT,
        ]);
    }

    protected static function useAuthorDescription(string $url): bool { return Providers::classify($url) !== null; }
}
