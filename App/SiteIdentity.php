<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class SiteIdentity
{
    public const LIGHT_THEME_COLOR = '#f6f6f4';
    public const DARK_THEME_COLOR = '#141416';
    public const MANIFEST_THEME_COLOR = '#64748b';
    private const CACHE_VERSION = '2';

    private const VARIANTS = [
        'favicon' => [
            'route' => '/favicon-32x32.png',
            'suffix' => '-32.png',
            'size' => 32,
            'scale' => 1.0,
            'background' => null,
        ],
        'apple' => [
            'route' => '/apple-touch-icon.png',
            'suffix' => '-apple-touch.png',
            'size' => 180,
            'scale' => 1.0,
            'background' => '#ffffff',
        ],
        'pwa-192' => [
            'route' => '/icon-192.png',
            'suffix' => '-192.png',
            'size' => 192,
            'scale' => 1.0,
            'background' => '#ffffff',
        ],
        'pwa-512' => [
            'route' => '/icon-512.png',
            'suffix' => '-512.png',
            'size' => 512,
            'scale' => 1.0,
            'background' => '#ffffff',
        ],
        'maskable-512' => [
            'route' => '/icon-maskable-512.png',
            'suffix' => '-maskable-512.png',
            'size' => 512,
            'scale' => 0.64,
            'background' => self::MANIFEST_THEME_COLOR,
        ],
    ];

    public static function metadata(): array
    {
        return [
            'manifest_url' => '/site.webmanifest?v=' . self::version(),
            'favicon_url' => self::iconUrl('favicon'),
            'apple_touch_icon_url' => self::iconUrl('apple'),
            'light_theme_color' => self::LIGHT_THEME_COLOR,
            'dark_theme_color' => self::DARK_THEME_COLOR,
        ];
    }

    public static function iconUrl(string $kind): string
    {
        $variant = self::variant($kind);
        $source = self::source();

        if ($variant === null || $source === null) {
            return '';
        }

        $variantPath = self::variantPath($source['path'], (string) $variant['suffix']);

        if (is_file($variantPath)) {
            return self::variantUrl($source['url'], (string) $variant['suffix']);
        }

        return (string) $variant['route'] . '?v=' . self::version();
    }

    public static function writeVariants(GdImage $source, string $directory, string $stem): void
    {
        $stem = preg_replace('/[^a-z0-9_-]/i', '', $stem) ?: 'favicon';

        if (!is_dir($directory)) {
            throw new RuntimeException('Site icon directory does not exist.');
        }

        $written = [];

        try {
            foreach (self::VARIANTS as $variant) {
                $path = $directory . DIRECTORY_SEPARATOR . $stem . (string) $variant['suffix'];
                $written[] = $path;
                $canvas = self::renderVariant($source, $variant);
                $saved = imagepng($canvas, $path, 9);
                imagedestroy($canvas);

                if (!$saved) {
                    throw new RuntimeException('Could not write site icon variant.');
                }

            }
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            throw $exception;
        }
    }

    public static function respondIcon(string $kind): never
    {
        $variant = self::variant($kind);
        $source = self::source();

        if ($variant === null || $source === null) {
            self::notFound();
        }

        $version = self::version();
        $etag = '"tinycat-icon-' . $kind . '-' . $version . '"';
        self::cacheHeaders($version, $etag);
        header('Content-Type: image/png');

        $variantPath = self::variantPath($source['path'], (string) $variant['suffix']);

        if (is_file($variantPath)) {
            header('Content-Length: ' . (string) filesize($variantPath));
            readfile($variantPath);
            exit;
        }

        if (!extension_loaded('gd') || !function_exists('imagecreatefromwebp')) {
            self::notFound();
        }

        $image = @imagecreatefromwebp($source['path']);

        if (!$image instanceof GdImage) {
            self::notFound();
        }

        $canvas = self::renderVariant($image, $variant);
        imagedestroy($image);
        ob_start();
        imagepng($canvas, null, 9);
        imagedestroy($canvas);
        $png = (string) ob_get_clean();
        header('Content-Length: ' . strlen($png));
        echo $png;
        exit;
    }

    public static function respondManifest(): never
    {
        $version = self::version();
        $etag = '"tinycat-manifest-' . $version . '"';
        self::cacheHeaders($version, $etag);
        header('Content-Type: application/manifest+json; charset=UTF-8');

        $name = trim((string) Core::config('site.name', 'TinyCat')) ?: 'TinyCat';
        $description = trim((string) Core::config('site.meta_description', ''));
        $icons = [];

        foreach ([
            'pwa-192' => ['sizes' => '192x192', 'purpose' => 'any'],
            'pwa-512' => ['sizes' => '512x512', 'purpose' => 'any'],
            'maskable-512' => ['sizes' => '512x512', 'purpose' => 'maskable'],
        ] as $kind => $definition) {
            $src = self::iconUrl($kind);

            if ($src !== '') {
                $icons[] = [
                    'src' => $src,
                    'sizes' => $definition['sizes'],
                    'type' => 'image/png',
                    'purpose' => $definition['purpose'],
                ];
            }
        }

        $manifest = [
            'id' => '/',
            'name' => $name,
            'short_name' => self::shortName($name),
            'description' => $description,
            'lang' => Core::locale(),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => self::LIGHT_THEME_COLOR,
            'theme_color' => self::MANIFEST_THEME_COLOR,
            'icons' => $icons,
        ];

        echo json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT
        );
        exit;
    }

    private static function variant(string $kind): ?array
    {
        return isset(self::VARIANTS[$kind]) ? self::VARIANTS[$kind] : null;
    }

    private static function source(): ?array
    {
        $relativePath = trim(str_replace('\\', '/', (string) Core::config('site.favicon_path', '')), '/');

        if (
            $relativePath === ''
            || str_contains($relativePath, '..')
            || !preg_match('~^[a-z0-9/_-]+\.webp$~i', $relativePath)
        ) {
            return null;
        }

        $path = Core::basePath('uploads/site/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if (!is_file($path)) {
            return null;
        }

        $url = trim((string) Core::config('site.favicon_url', ''));

        if ($url === '' || !str_starts_with($url, '/uploads/site/')) {
            $url = '/uploads/site/' . $relativePath;
        }

        return ['path' => $path, 'url' => $url];
    }

    private static function variantPath(string $sourcePath, string $suffix): string
    {
        $stem = pathinfo($sourcePath, PATHINFO_FILENAME);

        return dirname($sourcePath) . DIRECTORY_SEPARATOR . $stem . $suffix;
    }

    private static function variantUrl(string $sourceUrl, string $suffix): string
    {
        $path = (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl);
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $stem = pathinfo($path, PATHINFO_FILENAME);

        return ($directory !== '' ? $directory : '') . '/' . $stem . $suffix;
    }

    private static function version(): string
    {
        $source = self::source();
        $fingerprint = implode('|', [
            self::CACHE_VERSION,
            (string) Core::config('site.name', 'TinyCat'),
            (string) Core::config('site.meta_description', ''),
            (string) Core::config('i18n.locale', Core::config('install.locale', 'en')),
            (string) ($source['url'] ?? ''),
            $source !== null ? (string) filemtime($source['path']) : '',
            $source !== null ? (string) filesize($source['path']) : '',
        ]);

        return substr(hash('sha256', $fingerprint), 0, 12);
    }

    private static function shortName(string $name): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 30);
        }

        return substr($name, 0, 30);
    }

    private static function renderVariant(GdImage $source, array $variant): GdImage
    {
        $size = max(1, (int) ($variant['size'] ?? 32));
        $scale = max(0.1, min(1.0, (float) ($variant['scale'] ?? 1.0)));
        $background = $variant['background'] ?? null;
        $canvas = imagecreatetruecolor($size, $size);

        if (!$canvas instanceof GdImage) {
            throw new RuntimeException('Could not create site icon canvas.');
        }

        if (is_string($background) && preg_match('/^#([a-f0-9]{6})$/i', $background, $matches)) {
            $rgb = hexdec($matches[1]);
            $color = imagecolorallocate($canvas, ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $color);
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
            imagealphablending($canvas, true);
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $cropSize = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
        $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);
        $contentSize = max(1, (int) round($size * $scale));
        $offset = (int) floor(($size - $contentSize) / 2);

        imagecopyresampled(
            $canvas,
            $source,
            $offset,
            $offset,
            $sourceX,
            $sourceY,
            $contentSize,
            $contentSize,
            $cropSize,
            $cropSize
        );

        return $canvas;
    }

    private static function cacheHeaders(string $version, string $etag): void
    {
        header_remove('Expires');
        header_remove('Pragma');
        header('ETag: ' . $etag);

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        $requestedVersion = trim((string) ($_GET['v'] ?? ''));
        header('Cache-Control: ' . ($requestedVersion !== '' && hash_equals($version, $requestedVersion)
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=86400'));
    }

    private static function notFound(): never
    {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }
}
