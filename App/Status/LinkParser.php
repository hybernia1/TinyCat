<?php
declare(strict_types=1);

namespace TinyCat\Status;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class LinkParser
{
    private const MAX_LINKS = 5;
    private const TRACKING_PARAMS = [
        '_hsenc',
        '_hsmi',
        '_openstat',
        'ab_channel',
        'ck_subscriber_id',
        'dclid',
        'fbclid',
        'fb_action_ids',
        'fb_action_types',
        'fb_ref',
        'fb_source',
        'gclid',
        'gbraid',
        'igshid',
        'mc_cid',
        'mc_eid',
        'mkt_tok',
        'msclkid',
        'oly_anon_id',
        'oly_enc_id',
        'ref_src',
        'sc_customer',
        'sc_eh',
        'sc_llid',
        'sc_src',
        'srsltid',
        'vero_id',
        'wbraid',
        'yclid',
    ];
    /**
     * @return array<mixed>
     */
    public static function extract(string $text, int $limit = self::MAX_LINKS): array
    {
        $limit = max(1, min(10, $limit));

        if ($text === '' || !preg_match_all(self::pattern(), $text, $matches)) {
            return [];
        }

        $links = [];
        $seen = [];
        $parsed = [];

        foreach ((array) ($matches[0] ?? []) as $match) {
            $raw = (string) $match;
            [$url] = self::splitTail($raw);
            if (!array_key_exists($url, $parsed)) {
                $parsed[$url] = self::fromRaw($url);
            }
            $link = $parsed[$url];

            if ($link === null || isset($seen[$link['url_hash']])) {
                continue;
            }

            $seen[$link['url_hash']] = true;
            $links[] = $link;

            if (count($links) >= $limit) {
                break;
            }
        }

        return $links;
    }

    /**
     * @return array<mixed>|null
     */
    public static function fromRaw(string $raw): ?array
    {
        $url = self::withScheme($raw);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = self::host((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || mb_strlen($url) > 2048) {
            return null;
        }

        $video = \TinyCat\Status\Providers::classify(self::normalizeUrl($parts, $host));

        if ($video !== null) {
            $normalizedUrl = $video['normalized_url'];

            return [
                'normalized_url' => $normalizedUrl,
                'url_hash' => self::hash($normalizedUrl),
                'provider' => $video['provider'],
                'link_type' => $video['link_type'] ?? 'video',
                'title' => $video['title'],
                'description' => $video['description'],
                'video_id' => $video['video_id'],
                'display_url' => self::displayUrl($normalizedUrl),
            ];
        }

        $normalizedUrl = self::normalizeUrl($parts, $host);

        if ($normalizedUrl === '') {
            return null;
        }

        $displayUrl = $host . (($parts['path'] ?? '') !== '/' ? (string) ($parts['path'] ?? '') : '');
        $audio = Audio::isDirect($normalizedUrl) ? $normalizedUrl : '';

        return [
            'normalized_url' => $normalizedUrl,
            'url_hash' => self::hash($normalizedUrl),
            'provider' => '',
            'link_type' => $audio !== '' ? 'audio' : 'web',
            'audio_url' => $audio,
            'title' => self::title($host, (string) ($parts['path'] ?? '')),
            'description' => $displayUrl,
            'video_id' => '',
            'display_url' => $displayUrl,
        ];
    }

    public static function pattern(): string
    {
    /**
     * @return array<mixed>
     */
        return '~(?<![@\p{L}\p{N}_])(?:https?://(?:[^\s<>"\']|(?<=\d)\'(?=\d))+|(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:[/?#](?:[^\s<>"\']|(?<=\d)\'(?=\d))*)?)~iu';
    }

    /** @return array<mixed> */
    public static function splitTail(string $url): array
    {
        $length = strlen($url);
        $end = $length;
        $pairs = [')' => '(', ']' => '[', '}' => '{'];
        $excess = [];
        foreach ($pairs as $close => $open) {
            $excess[$close] = substr_count($url, $close) - substr_count($url, $open);
        }
        while ($end > 0) {
            $last = $url[$end - 1];
            if (isset($pairs[$last])) {
                if ($excess[$last] <= 0) { break; }
                $excess[$last]--;
            } elseif (!str_contains('.,;:!?', $last)) {
                break;
            }
            $end--;
        }

        return [substr($url, 0, $end), substr($url, $end)];
    }

    /**
     * @param array<mixed> $parts
     */
    private static function normalizeUrl(array $parts, string $host): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $path = (string) ($parts['path'] ?? '/');
        $query = self::cleanQuery((string) ($parts['query'] ?? ''));
        $portNumber = isset($parts['port']) ? (int) $parts['port'] : 0;
        $port = $portNumber > 0 && !(($scheme === 'http' && $portNumber === 80) || ($scheme === 'https' && $portNumber === 443))
            ? ':' . $portNumber
            : '';

        if ($path === '') {
            $path = '/';
        }

        return $scheme . '://' . $host . $port . ($path !== '/' ? $path : '') . ($query !== '' ? '?' . $query : '')
            . (isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '');
    }

    private static function cleanQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        foreach (explode('&', $query) as $parameter) {
            $name = strtolower(urldecode(explode('=', $parameter, 2)[0]));
            if (in_array($name, ['signature', 'sig', 'hmac', 'x-amz-signature', 'x-goog-signature'], true)) { return $query; }
        }

        // Decode only the name for tracking detection. Rebuilding query data
        // would alter repeated keys, dotted names, flags and signed values.
        $kept = [];
        foreach (explode('&', $query) as $parameter) {
            $name = strtolower(urldecode(explode('=', $parameter, 2)[0]));
            if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_PARAMS, true)) {
                continue;
            }
            $kept[] = $parameter;
        }

        return implode('&', $kept);
    }

    private static function title(string $host, string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return $host;
        }

        $last = basename($path);
        $last = trim(str_replace(['-', '_', '+'], ' ', rawurldecode($last)));

        return $last !== '' ? $last : $host;
    }

    private static function displayUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = self::host((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return $host . ($path !== '/' ? $path : '');
    }

    private static function withScheme(string $url): string
    {
        $url = trim($url);
        if (preg_match('/[\x00-\x20\x7F<>"\\\\]/', $url) === 1) { return ''; }

        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }

        if (preg_match('~^(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:[/?#]|$)~i', $url) === 1) {
            return 'https://' . $url;
        }

        return preg_match('~^https?://~i', $url) === 1 ? $url : '';
    }

    private static function host(string $host): string
    {
        $host = strtolower(trim($host, ". \t\n\r\0\x0B[]"));

        return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : '';
    }

    private static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
