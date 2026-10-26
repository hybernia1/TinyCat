<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class Paths
{

    public static function basePath(string $path = ''): string
    {
        $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return dirname(__DIR__, 2) . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
    }

    public static function publicPath(string $path = ''): string
    {
        $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return self::basePath($path === '' ? 'Public' : 'Public' . DIRECTORY_SEPARATOR . $path);
    }
}
