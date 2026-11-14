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
            'css' => ClientAssets::option('performance.minify_css'),
            'js' => ClientAssets::option('performance.minify_js'),
            default => false,
        };

        $compiled = CompiledAssets::asset($path, $minify);
        if ($compiled !== null) { return $compiled; }

        if ($minify) {
            $optimizedUrl = \TinyCat\Support\Minifier::assetUrl($path, $file, $extension);

            if ($optimizedUrl !== null) {
                return $optimizedUrl;
            }
        }

        return $url . '?v=' . substr((string) hash_file('sha256', $file), 0, 24);
    }
}
