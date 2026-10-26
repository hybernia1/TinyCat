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
    private const SOCIAL_HOSTS = [
        'bsky.app',
        'discord.com',
        'discord.gg',
        'facebook.com',
        'fb.com',
        'fb.watch',
        'instagram.com',
        'linkedin.com',
        'm.facebook.com',
        'm.instagram.com',
        'm.tiktok.com',
        'm.twitter.com',
        'm.x.com',
        'pinterest.com',
        'reddit.com',
        'snapchat.com',
        't.co',
        'threads.net',
        'tiktok.com',
        'twitter.com',
        'www.facebook.com',
        'www.instagram.com',
        'www.linkedin.com',
        'www.pinterest.com',
        'www.reddit.com',
        'www.threads.net',
        'www.tiktok.com',
        'www.twitter.com',
        'www.x.com',
        'x.com',
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

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = self::host((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || self::isSocialHost($host)) {
            return null;
        }

        $video = self::video($parts, $host);

        if ($video !== null) {
            $normalizedUrl = $video['normalized_url'];

            return [
                'normalized_url' => $normalizedUrl,
                'url_hash' => self::hash($normalizedUrl),
                'provider' => $video['provider'],
                'link_type' => 'video',
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

        return [
            'normalized_url' => $normalizedUrl,
            'url_hash' => self::hash($normalizedUrl),
            'provider' => 'web',
            'link_type' => 'link',
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
        return '~(?<![@\p{L}\p{N}_])(?:https?://[^\s<>"\']+|(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:[/?#][^\s<>"\']*)?)~iu';
    }

    /**
     * @return array<mixed>
     */
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

    public static function isSocialHost(string $host): bool
    {
        $host = self::host($host);

        if ($host === '') {
            return false;
        }

        return array_any(
    /**
     * @param array<mixed> $parts
     * @return array<mixed>|null
     */
            self::SOCIAL_HOSTS,
            static fn (string $socialHost): bool => $host === $socialHost || str_ends_with($host, '.' . $socialHost)
        );
    }

    /**
     * @param array<mixed> $parts
     * @return array<mixed>|null
     */
    private static function video(array $parts, string $host): ?array
    {
        $youtubeId = self::youtubeId($parts, $host);

        if ($youtubeId !== '') {
            return [
                'provider' => 'youtube',
                'title' => 'YouTube video',
                'description' => 'youtube.com',
                'video_id' => $youtubeId,
                'normalized_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($youtubeId),
            ];
        }

        $vimeoId = self::vimeoId($parts, $host);

        if ($vimeoId !== '') {
            return [
                'provider' => 'vimeo',
                'title' => 'Vimeo video',
                'description' => 'vimeo.com',
                'video_id' => $vimeoId,
                'normalized_url' => 'https://vimeo.com/' . rawurlencode($vimeoId),
            ];
        }

        $dailymotionId = self::dailymotionId($parts, $host);

        if ($dailymotionId !== '') {
            return [
                'provider' => 'dailymotion',
                'title' => 'Dailymotion video',
                'description' => 'dailymotion.com',
                'video_id' => $dailymotionId,
                'normalized_url' => 'https://www.dailymotion.com/video/' . rawurlencode($dailymotionId),
            ];
        }

        return null;
    }

    /**
     * @param array<mixed> $parts
     */
    private static function youtubeId(array $parts, string $host): string
    {
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'youtu.be') {
            return self::videoIdToken(explode('/', $path)[0]);
        }

        if (!self::hostMatches($host, ['youtube.com', 'youtube-nocookie.com'])) {
            return '';
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        if (isset($query['v']) && is_string($query['v'])) {
            return self::videoIdToken((string) $query['v']);
        }

        if (preg_match('~^(?:shorts|embed|live)/([^/?#]+)~i', $path, $match) === 1) {
            return self::videoIdToken((string) $match[1]);
        }

        return '';
    }

    /**
     * @param array<mixed> $parts
     */
    private static function vimeoId(array $parts, string $host): string
    {
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'player.vimeo.com' && preg_match('~^video/([0-9]+)~', $path, $match) === 1) {
            return (string) $match[1];
        }

        if (self::hostMatches($host, ['vimeo.com']) && preg_match('~(?:^|/)([0-9]{5,})$~', $path, $match) === 1) {
            return (string) $match[1];
        }

        return '';
    }

    /**
     * @param array<mixed> $parts
     */
    private static function dailymotionId(array $parts, string $host): string
    {
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'dai.ly') {
            return self::videoIdToken(explode('/', $path)[0]);
        }

        if (self::hostMatches($host, ['dailymotion.com']) && preg_match('~^video/([^/?#]+)~i', $path, $match) === 1) {
            return self::videoIdToken((string) $match[1]);
        }

        return '';
    }

    private static function videoIdToken(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_-]{5,40}$/', $value) === 1 ? $value : '';
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

        return $scheme . '://' . $host . $port . ($path !== '/' ? $path : '') . ($query !== '' ? '?' . $query : '');
    }

    private static function cleanQuery(string $query): string
    {
        if ($query === '') {
            return '';
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
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $url));

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

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : '';
    }

    /**
     * @param array<mixed> $domains
     */
    private static function hostMatches(string $host, array $domains): bool
    {
        return array_any(
            $domains,
            static fn (string $domain): bool => $host === $domain || str_ends_with($host, '.' . $domain)
        );
    }

    private static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
