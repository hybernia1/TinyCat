<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** Application-supplied URL policy for shared renderers and redirects. */
final class LocalUrls
{
    private static ?\Closure $resolver = null;

    public static function configure(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function resolve(string $url): string
    {
        return self::$resolver !== null ? (self::$resolver)($url) : $url;
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
