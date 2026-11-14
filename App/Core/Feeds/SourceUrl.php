<?php
declare(strict_types=1);
namespace TinyCat\Core\Feeds;

/** Stable feed-source identity, independent of publication storage. */
final class SourceUrl
{
    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url)); if (!is_array($parts)) { return ''; }
        $scheme = strtolower((string) ($parts['scheme'] ?? '')); $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') { return ''; }
        $port = (int) ($parts['port'] ?? 0); $default = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $path = (string) ($parts['path'] ?? '/'); $path = $path === '/' ? '/' : rtrim($path, '/');
        return $scheme . '://' . $host . ($port > 0 && !$default ? ':' . $port : '') . $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }
}
