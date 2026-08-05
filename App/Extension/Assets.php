<?php
declare(strict_types=1);

namespace TinyCat\Extension;

use Cache;
use Core;
use InvalidArgumentException;
use Minifier;
use RuntimeException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Publishes private extension CSS and JavaScript through the public asset cache.
 */
final class Assets
{
    private const int MAX_SOURCE_BYTES = 2 * 1024 * 1024;
    private const int MAX_ASSETS_PER_EXTENSION = 20;
    private const string CACHE_VERSION = '1';
    private const string CACHE_NAMESPACE = 'assets';
    private const string CACHE_URL = '/cache/assets';

    private static array $providers = [];

    private function __construct()
    {
    }

    public static function register(string $slug, mixed $provider): void
    {
        if ($provider === null) {
            return;
        }

        if (!is_callable($provider)) {
            throw new InvalidArgumentException('Invalid extension asset provider.');
        }

        if (isset(self::$providers[$slug])) {
            throw new RuntimeException('Extension asset provider is already registered: ' . $slug);
        }

        self::$providers[$slug] = $provider;
    }

    /**
     * @return array{styles: list<string>, scripts: list<string>}
     */
    public static function forPath(string $path): array
    {
        $published = ['styles' => [], 'scripts' => []];

        foreach (self::$providers as $slug => $provider) {
            $definition = $provider(Core::path($path));
            if ($definition === []) {
                continue;
            }

            if (!is_array($definition) || array_is_list($definition)) {
                throw new RuntimeException('Extension asset provider must return an object: ' . $slug);
            }

            $unknown = array_diff(array_keys($definition), ['styles', 'scripts']);
            if ($unknown !== []) {
                throw new RuntimeException('Extension asset provider returned an unknown asset group: ' . $slug);
            }

            $count = 0;
            foreach (['styles' => 'css', 'scripts' => 'js'] as $group => $type) {
                $paths = $definition[$group] ?? [];
                if (!is_array($paths) || !array_is_list($paths)) {
                    throw new RuntimeException('Extension asset group must be a list: ' . $slug . '/' . $group);
                }

                $count += count($paths);
                if ($count > self::MAX_ASSETS_PER_EXTENSION) {
                    throw new RuntimeException('Extension asset provider returned too many files: ' . $slug);
                }

                foreach ($paths as $relativePath) {
                    if (!is_string($relativePath) || trim($relativePath) === '') {
                        throw new RuntimeException('Extension asset path must be a non-empty string: ' . $slug);
                    }

                    $published[$group][] = self::url($slug, $relativePath, $type);
                }
            }
        }

        $published['styles'] = array_values(array_unique($published['styles']));
        $published['scripts'] = array_values(array_unique($published['scripts']));

        return $published;
    }

    public static function url(string $slug, string $relativePath, string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['css', 'js'], true)) {
            throw new InvalidArgumentException('Invalid extension asset type.');
        }

        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/')) {
            throw new InvalidArgumentException('Extension asset path must be relative.');
        }

        if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== $type) {
            throw new InvalidArgumentException('Extension asset has an invalid file type.');
        }

        $sourceFile = Registry::file($slug, $relativePath);
        $size = filesize($sourceFile);
        if (!is_int($size) || $size > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Extension asset exceeds the maximum size of 2 MiB.');
        }

        $source = file_get_contents($sourceFile);
        if (!is_string($source)) {
            throw new RuntimeException('Could not read extension asset: ' . $slug . '/' . $relativePath);
        }

        $minified = (bool) Core::setting('performance.minify_' . $type, false);
        $content = $minified
            ? ($type === 'css' ? Minifier::minifyCss($source) : Minifier::minifyJavaScript($source))
            : $source;

        $baseName = strtolower((string) pathinfo($relativePath, PATHINFO_FILENAME));
        $baseName = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $baseName), '-');
        $baseName = substr($baseName !== '' ? $baseName : 'asset', 0, 40);
        $identity = substr(hash('sha256', $slug . "\0" . $relativePath), 0, 10);
        $prefix = 'ext-' . $slug . '-' . $baseName . '-' . $identity;
        $hash = substr(hash('sha256', self::CACHE_VERSION . "\0" . ($minified ? 'min' : 'raw') . "\0" . $content), 0, 20);
        $fileName = $prefix . '.' . $hash . ($minified ? '.min' : '') . '.' . $type;
        $target = Cache::file($fileName, self::CACHE_NAMESPACE);

        if (!is_file($target)) {
            if (!Cache::writeFile($fileName, $content, self::CACHE_NAMESPACE)) {
                throw new RuntimeException('Could not publish extension asset: ' . $slug . '/' . $relativePath);
            }

            $pattern = '/^' . preg_quote($prefix, '/') . '\\.[a-f0-9]{20}(?:\\.min)?\\.' . preg_quote($type, '/') . '$/';
            Cache::prune(
                self::CACHE_NAMESPACE,
                static fn (string $candidate): bool => $candidate !== $fileName
                    && preg_match($pattern, $candidate) === 1
            );
        }

        return self::CACHE_URL . '/' . rawurlencode($fileName);
    }
}
