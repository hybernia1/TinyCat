<?php
declare(strict_types=1);

namespace TinyCat\Support;

use \TinyCat\Support\Config;

final class Assets
{

    public static function asset(string $path, ?bool $version = null): string
    {
        Config::load();

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $url = '/assets/' . $path;

        if ($version === false) {
            return $url;
        }

        $file = \TinyCat\Support\Paths::basePath('assets/' . str_replace('/', DIRECTORY_SEPARATOR, $path));

        if (!is_file($file)) {
            return $url;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $minify = match ($extension) {
            'css' => (bool) \TinyCat\Settings\Store::setting('performance.minify_css', false),
            'js' => (bool) \TinyCat\Settings\Store::setting('performance.minify_js', false),
            default => false,
        };

        if ($minify) {
            $optimizedUrl = \TinyCat\Support\Minifier::assetUrl($path, $file, $extension);

            if ($optimizedUrl !== null) {
                return $optimizedUrl;
            }
        }

        return $url . '?v=' . filemtime($file);
    }
}
