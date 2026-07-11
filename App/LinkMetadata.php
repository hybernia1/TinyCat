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

        $response = self::request($url, self::HTML_LIMIT, 'text/html,application/xhtml+xml');

        if ($response === null || !self::isHtml((string) ($response['content_type'] ?? ''))) {
            return [];
        }

        return self::parseHtml((string) ($response['body'] ?? ''), (string) ($response['url'] ?? $url));
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
        $titles = $dom->getElementsByTagName('title');

        if ($titles->length > 0) {
            $meta['title'] = self::cleanText((string) $titles->item(0)?->textContent, 255);
        }

        foreach ($dom->getElementsByTagName('meta') as $node) {
            $name = strtolower(trim((string) ($node->getAttribute('property') ?: $node->getAttribute('name'))));
            $content = trim((string) $node->getAttribute('content'));

            if ($name === '' || $content === '') {
                continue;
            }

            if (in_array($name, ['og:title', 'twitter:title'], true)) {
                $meta['title'] = self::cleanText($content, 255);
            } elseif (in_array($name, ['description', 'og:description', 'twitter:description'], true)) {
                $meta['description'] = self::cleanText($content, 500);
            } elseif (in_array($name, ['og:image', 'og:image:secure_url', 'twitter:image', 'twitter:image:src'], true)) {
                $image = self::absoluteUrl($baseUrl, $content);

                if ($image !== '' && self::safeUrl($image)) {
                    $meta['image_url'] = $image;
                }
            }
        }

        return array_filter($meta, static fn (mixed $value): bool => trim((string) $value) !== '');
    }

    private static function request(string $url, int $limit, string $accept, int $redirects = 0): ?array
    {
        if ($redirects > self::REDIRECT_LIMIT || !self::safeUrl($url)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
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

            return $next !== '' ? self::request($next, $limit, $accept, $redirects + 1) : null;
        }

        if ($status >= 400) {
            fclose($stream);

            return null;
        }

        $body = stream_get_contents($stream, $limit + 1);
        fclose($stream);

        if ($body === false || strlen($body) > $limit) {
            return null;
        }

        return [
            'url' => $url,
            'status' => $status,
            'content_type' => (string) ($headers['content-type'] ?? ''),
            'body' => $body,
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
