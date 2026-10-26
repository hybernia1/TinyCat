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
}
