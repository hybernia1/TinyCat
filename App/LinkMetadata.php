<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class LinkMetadata
{
    private const USER_AGENT = 'TinyCatLinkPreview/1.0';
    private const HTML_LIMIT = 360000;
    private const OEMBED_LIMIT = 32768;
    private const TIMEOUT = 4;
    private const FEED_TIMEOUT = 10;
    private const FEED_LIMIT = 2097152;
    private const IMAGE_TIMEOUT = 8;
    private const IMAGE_LIMIT = 5242880;
    private const REDIRECT_LIMIT = 3;

    public static function enrich(array $link): array
    {
        $link['_metadata_fetched'] = false;
        $type = (string) ($link['link_type'] ?? 'link');
        $url = (string) ($link['normalized_url'] ?? '');

        if ($url === '') {
            return $link;
        }

        if (!self::shouldFetch($link)) {
            return $link;
        }

        $meta = $type === 'video' ? self::fetchVideo($link) : self::fetch($url);

        if ($meta === []) {
            return $link;
        }

        $link['_metadata_fetched'] = true;

        foreach (['title', 'description'] as $key) {
            if (!empty($meta[$key])) {
                $link[$key] = (string) $meta[$key];
            }
        }

        if (!empty($meta['image_url'])) {
            $link['image_url'] = (string) $meta['image_url'];
        }

        return $link;
    }

    private static function fetchVideo(array $link): array
    {
        $provider = (string) ($link['provider'] ?? '');
        $url = (string) ($link['normalized_url'] ?? '');

        if ($url === '' || !self::safeUrl($url)) {
            return [];
        }

        $oembedUrl = match ($provider) {
            'youtube' => 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url),
            'vimeo' => 'https://vimeo.com/api/oembed.json?url=' . rawurlencode($url),
            'dailymotion' => 'https://www.dailymotion.com/services/oembed?format=json&url=' . rawurlencode($url),
            default => '',
        };

        if ($oembedUrl === '') {
            return [];
        }

        $response = self::request($oembedUrl, self::OEMBED_LIMIT, 'application/json,text/json');

        if ($response === null || !self::isJson((string) ($response['content_type'] ?? ''))) {
            return [];
        }

        $payload = json_decode((string) ($response['body'] ?? ''), true);

        if (!is_array($payload)) {
            return [];
        }

        $meta = [];
        $title = self::cleanText((string) ($payload['title'] ?? ''), 255);
        $author = self::cleanText((string) ($payload['author_name'] ?? ''), 500);
        $thumbnail = self::absoluteUrl($url, (string) ($payload['thumbnail_url'] ?? ''));

        if ($title !== '') {
            $meta['title'] = $title;
        }

        if ($author !== '') {
            $meta['description'] = $author;
        }

        if ($thumbnail !== '' && self::safeUrl($thumbnail)) {
            $meta['image_url'] = $thumbnail;
        }

        return $meta;
    }

    private static function shouldFetch(array $link): bool
    {
        $type = (string) ($link['link_type'] ?? 'link');

        if ($type === 'link') {
            return true;
        }

        if ($type !== 'video') {
            return false;
        }

        return in_array((string) ($link['provider'] ?? ''), ['youtube', 'vimeo', 'dailymotion'], true);
    }

    public static function fetch(string $url): array
    {
        if (!self::safeUrl($url)) {
            return [];
        }

        $response = self::request($url, self::HTML_LIMIT, 'text/html,application/xhtml+xml', 0, true);

        if ($response === null || !self::isHtml((string) ($response['content_type'] ?? ''))) {
            return [];
        }

        return self::parseHtml((string) ($response['body'] ?? ''), (string) ($response['url'] ?? $url));
    }

    public static function fetchDocument(string $url, int $limit = self::FEED_LIMIT, string $accept = 'application/rss+xml,application/atom+xml,application/xml,text/xml'): ?array
    {
        $limit = max(1024, min(self::FEED_LIMIT, $limit));
        return self::requestCurl($url, $limit, $accept, 0, false, self::FEED_TIMEOUT)
            ?? self::request($url, $limit, $accept, 0, false, self::FEED_TIMEOUT);
    }

    public static function isSafeRemoteUrl(string $url): bool
    {
        return self::safeUrl($url);
    }

    public static function fetchImage(string $url): ?array
    {
        $response = self::request(
            $url,
            self::IMAGE_LIMIT,
            'image/webp,image/jpeg,image/png,image/gif',
            0,
            false,
            self::IMAGE_TIMEOUT
        );

        if ($response === null || !str_starts_with(strtolower((string) ($response['content_type'] ?? '')), 'image/')) {
            return null;
        }

        return $response;
    }

    private static function parseHtml(string $html, string $baseUrl): array
    {
        if ($html === '' || !class_exists(DOMDocument::class)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

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
        $titles = $dom->getElementsByTagName('title');

        if ($titles->length > 0) {
            $candidates['title'] = self::cleanText((string) $titles->item(0)?->textContent, 255);
        }

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
                $image = self::absoluteUrl($baseUrl, $content);

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

            $image = self::absoluteUrl($baseUrl, (string) $node->getAttribute('href'));
            if ($image !== '' && self::safeUrl($image)) {
                $candidates['image'] = $image;
                break;
            }
        }

        $meta['title'] = $candidates['title'] ?: $candidates['og_title'] ?: $candidates['twitter_title'];
        $meta['description'] = $candidates['description'] ?: $candidates['og_description'] ?: $candidates['twitter_description'];
        $meta['image_url'] = $candidates['og_image_secure'] ?: $candidates['og_image'] ?: $candidates['twitter_image'] ?: $candidates['image'];

        return array_filter($meta, static fn (mixed $value): bool => trim((string) $value) !== '');
    }

    private static function request(string $url, int $limit, string $accept, int $redirects = 0, bool $allowTruncated = false, int $timeout = self::TIMEOUT): ?array
    {
        if ($redirects > self::REDIRECT_LIMIT || !self::safeUrl($url)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, min(self::FEED_TIMEOUT, $timeout)),
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'user_agent' => self::USER_AGENT,
                'header' => "Accept: {$accept};q=1,*/*;q=0.2\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);

        if (!is_resource($stream)) {
            return null;
        }

        $meta = stream_get_meta_data($stream);
        $headers = self::headers((array) ($meta['wrapper_data'] ?? []));
        $status = (int) ($headers['status'] ?? 0);

        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            fclose($stream);
            $next = self::absoluteUrl($url, (string) $headers['location']);

            return $next !== '' ? self::request($next, $limit, $accept, $redirects + 1, $allowTruncated, $timeout) : null;
        }

        if ($status >= 400) {
            fclose($stream);

            return null;
        }

        $body = stream_get_contents($stream, $limit + 1);
        fclose($stream);

        if ($body === false || (!$allowTruncated && strlen($body) > $limit)) {
            return null;
        }

        if ($allowTruncated && strlen($body) > $limit) {
            $body = substr($body, 0, $limit);
        }

        return [
            'url' => $url,
            'status' => $status,
            'content_type' => (string) ($headers['content-type'] ?? ''),
            'body' => $body,
        ];
    }

    private static function requestCurl(
        string $url,
        int $limit,
        string $accept,
        int $redirects = 0,
        bool $allowTruncated = false,
        int $timeout = self::TIMEOUT
    ): ?array {
        if ($redirects > self::REDIRECT_LIMIT || !self::safeUrl($url) || !function_exists('curl_init')) {
            return null;
        }

        $body = '';
        $rawHeaders = [];
        $overflow = false;
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, min(self::FEED_TIMEOUT, $timeout)),
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept . ';q=1,*/*;q=0.2'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$rawHeaders): int {
                $rawHeaders[] = trim($line);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body, &$overflow, $limit): int {
                $remaining = $limit + 1 - strlen($body);
                if ($remaining <= 0) {
                    $overflow = true;
                    return 0;
                }

                $body .= substr($chunk, 0, $remaining);
                if (strlen($chunk) > $remaining) {
                    $overflow = true;
                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($overflow && !$allowTruncated) {
            return null;
        }
        if ($result === false && !($overflow && $allowTruncated)) {
            return null;
        }

        $headers = self::headers($rawHeaders);
        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            $next = self::absoluteUrl($url, (string) $headers['location']);
            return $next !== ''
                ? self::requestCurl($next, $limit, $accept, $redirects + 1, $allowTruncated, $timeout)
                : null;
        }
        if ($status >= 400 || $status < 200) {
            return null;
        }

        return [
            'url' => $url,
            'status' => $status,
            'content_type' => $contentType !== '' ? $contentType : (string) ($headers['content-type'] ?? ''),
            'body' => $allowTruncated ? substr($body, 0, $limit) : $body,
        ];
    }

    private static function headers(array $raw): array
    {
        $headers = [];

        foreach ($raw as $line) {
            $line = (string) $line;

            if (preg_match('~^HTTP/\S+\s+([0-9]{3})~i', $line, $match) === 1) {
                $headers['status'] = (int) ($match[1] ?? 0);
                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }

    private static function safeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && !StatusLinks::isSocialHost($host)
            && self::publicHost($host);
    }

    private static function publicHost(string $host): bool
    {
        $host = trim($host, '[]');
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, $flags) !== false;
        }

        $ips = [];

        foreach ((array) gethostbynamel($host) as $ip) {
            $ips[] = $ip;
        }

        foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                return false;
            }
        }

        return true;
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

    private static function absoluteUrl(string $baseUrl, string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) {
            return '';
        }

        if (preg_match('~^https?://~i', $url) === 1) {
            return $url;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return '';
        }

        $origin = strtolower((string) $base['scheme']) . '://' . strtolower((string) $base['host']) . (isset($base['port']) ? ':' . (int) $base['port'] : '');

        if (str_starts_with($url, '//')) {
            return strtolower((string) $base['scheme']) . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin . ($directory !== '' ? $directory : '') . '/' . $url;
    }
}
