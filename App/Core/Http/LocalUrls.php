<?php
declare(strict_types=1);

namespace TinyCat\Http;

/** Local and absolute HTTP URLs shared by renderers and redirects. */
final class LocalUrls
{
    public static function resolve(string $url): string
    {
        return LocaleRouting::url($url);
    }
    public static function absolute(string $url = ''): string
    {
        $url = trim($url);

        if ($url === '') {
            $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        }

        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = \TinyCat\Http\Request::current()->requestScheme();

            return $scheme . ':' . $url;
        }

        $path = '/' . ltrim($url, '/');
        $base = rtrim((string) \TinyCat\Core\Runtime\Startup::option('app.url', ''), '/');

        if ($base !== '') {
            return $base . $path;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return \TinyCat\Http\Request::current()->requestScheme() . '://' . $host . $path;
    }
}
