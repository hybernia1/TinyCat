<?php
declare(strict_types=1);
namespace TinyCat\Core\Links;

/** Local/external URL classification independent of domain storage. */
class Urls
{
    /** @var \Closure(): string|null */
    private static ?\Closure $base = null;
    /** @param \Closure(): string|null $base */
    public static function configure(?\Closure $base): void { self::$base = $base; }
    private static function baseUrl(): string { return self::$base !== null ? (self::$base)() : \TinyCat\Http\LocalUrls::absolute('/'); }

    public static function hostKey(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            return '';
        }

        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    public static function isInternal(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }

        $currentHost = self::hostKey(self::baseUrl());
        $urlHost = self::hostKey($url);

        return $currentHost !== '' && $urlHost !== '' && $currentHost === $urlHost;
    }

    public static function internalPath(string $url): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return \TinyCat\Http\Request::current()->path((string) (parse_url($url, PHP_URL_PATH) ?: $url));
        }

        if (!preg_match('~^https?://~i', $url) || !self::isInternal($url)) {
            return '';
        }

        return \TinyCat\Http\Request::current()->path((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    }

}
