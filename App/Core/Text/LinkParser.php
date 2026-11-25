<?php
declare(strict_types=1);

namespace TinyCat\Core\Text;


if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

class LinkParser
{
    /**
     * @return array<mixed>
     */
    public static function extract(string $text, int $limit = PHP_INT_MAX): array
    {
        $limit = max(1, $limit);

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
                $parsed[$url] = static::fromRaw($url);
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

        $normalizedUrl = self::normalizeUrl($parts, $host);

        if ($normalizedUrl === '') {
            return null;
        }

        $displayUrl = $host . (($parts['path'] ?? '') !== '/' ? (string) ($parts['path'] ?? '') : '');

        return [
            'normalized_url' => $normalizedUrl,
            'url_hash' => self::hash($normalizedUrl),
            'link_type' => 'web',
            'title' => self::title($host, (string) ($parts['path'] ?? '')),
            'description' => $displayUrl,
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
        $query = (string) ($parts['query'] ?? '');
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
