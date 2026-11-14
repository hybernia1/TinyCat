<?php
declare(strict_types=1);
namespace TinyCat\Core\Files;

use GdImage;
use RuntimeException;

/** Derived raster cache, protected by lazy references from every installed owner. */
final class RemoteImageCache
{
    public static function store(string $imageUrl, string $variant = 'card'): string
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || !extension_loaded('gd') || !function_exists('imagewebp')) {
            return '';
        }
        if (str_starts_with($imageUrl, '/uploads/links/')) {
            if (!self::exists($imageUrl)) { return ''; }
            @touch(\TinyCat\Support\Paths::basePath(ltrim($imageUrl, '/')));
            return $imageUrl;
        }
        if (!\TinyCat\Core\Links\Metadata::isSafeRemoteUrl($imageUrl)) {
            return '';
        }

        if (!in_array($variant, ['card', 'social', 'video', 'reel'], true)) { throw new \InvalidArgumentException('Unknown link image variant.'); }
        $subfolder = date('Y/m');
        $filename = substr(hash('sha256', $variant === 'card' ? $imageUrl : $imageUrl . "\0" . $variant), 0, 40) . '.webp';
        $directory = \TinyCat\Support\Paths::basePath('uploads/links/' . $subfolder);
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $localUrl = '/uploads/links/' . $subfolder . '/' . $filename;

        if (is_file($target)) {
            @touch($target);
            return $localUrl;
        }

        $response = \TinyCat\Core\Links\Metadata::fetchImage($imageUrl);
        $body = (string) ($response['body'] ?? '');
        $info = $body !== '' && function_exists('getimagesizefromstring') ? @getimagesizefromstring($body) : false;
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $width = is_array($info) ? (int) $info[0] : 0;
        $height = is_array($info) ? (int) $info[1] : 0;
        $mime = is_array($info) ? strtolower((string) $info['mime']) : '';

        if (
            $response === null
            || !in_array($mime, $allowedMimes, true)
            || $width < 1
            || $height < 1
            || $width > 8192
            || $height > 8192
            || $height > intdiv(20_000_000, $width)
        ) {
            return '';
        }

        $source = @imagecreatefromstring($body);
        if (!$source instanceof GdImage) {
            return '';
        }

        $targetWidth = 184;
        $targetHeight = 172;
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $width / $height;
        $cropWidth = $width;
        $cropHeight = $height;
        $sourceX = 0;
        $sourceY = 0;

        if ($sourceRatio > $targetRatio) {
            $cropWidth = max(1, (int) round($height * $targetRatio));
            $sourceX = (int) floor(($width - $cropWidth) / 2);
        } elseif ($sourceRatio < $targetRatio) {
            $cropHeight = max(1, (int) round($width / $targetRatio));
            $sourceY = (int) floor(($height - $cropHeight) / 2);
        }

        if ($variant !== 'card') {
            [$maxWidth, $maxHeight] = match ($variant) { 'social' => [800, 600], 'reel' => [540, 960], default => [960, 540] };
            $scale = min(1, $maxWidth / $width, $maxHeight / $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $sourceX = $sourceY = 0;
            $cropWidth = $width;
            $cropHeight = $height;
        }

        $canvas = \TinyCat\Core\Security\Images::image_resample($source, $targetWidth, $targetHeight, $sourceX, $sourceY, $cropWidth, $cropHeight);
        imagedestroy($source);

        if (!\TinyCat\Support\Storage::ensureDirectory($directory)) {
            imagedestroy($canvas);
            return '';
        }

        try {
            \TinyCat\Core\Security\Images::image_write_webp($canvas, $target, [$variant === 'card' ? 62 : 78], keepExisting: true);
            return $localUrl;
        } catch (RuntimeException) {
            return '';
        } finally {
            imagedestroy($canvas);
        }
    }

    /** @return array{changed: int, has_more: bool} */
    public static function cleanup(int $limit, string $relativeDirectory = ''): array
    {
        if ($relativeDirectory !== '' && preg_match('~^[0-9]{4}/[0-9]{2}$~D', $relativeDirectory) !== 1) {
            throw new \InvalidArgumentException('Invalid link image cleanup directory.');
        }
        $base = \TinyCat\Support\Paths::basePath('uploads/links');
        $directory = $base . ($relativeDirectory !== '' ? '/' . $relativeDirectory : '');
        $resolvedBase = realpath($base);
        $resolvedDirectory = realpath($directory);
        if ($resolvedBase === false || $resolvedDirectory === false) { return ['changed' => 0, 'has_more' => false]; }
        if ($resolvedDirectory !== $resolvedBase && !str_starts_with($resolvedDirectory, $resolvedBase . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Link image directory escapes upload storage.');
        }
        return \TinyCat\Support\FileSweep::run($directory, $limit, static function (array $files) use ($base): array {
            $paths = [];
            foreach ($files as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                if ($file->getMTime() <= time() - 3600 && preg_match('~^[0-9]{4}/[0-9]{2}/[a-f0-9]{40}\.webp$~D', $relative) === 1) {
                    $paths['/uploads/links/' . $relative] = $file->getPathname();
                }
            }
            $stored = array_fill_keys(References::retained('link-images', array_keys($paths)), true);
            $changed = 0;
            foreach ($paths as $url => $path) {
                clearstatcache(true, $path);
                $modified = @filemtime($path);
                if (!isset($stored[$url]) && $modified !== false && $modified <= time() - 3600 && \TinyCat\Support\FileSweep::remove($path)) { $changed++; }
            }
            return ['changed' => $changed, 'has_more' => false];
        });
    }

    public static function exists(string $url): bool
    {
        if (!str_starts_with($url, '/uploads/links/')) { return false; }
        $relative = str_replace('\\', '/', trim(substr($url, strlen('/uploads/links/')), '/'));
        if (!preg_match('~^[0-9]{4}/[0-9]{2}/[a-f0-9]{40}\.webp$~', $relative) || str_contains($relative, '..')) {
            return false;
        }

        return is_file(\TinyCat\Support\Paths::basePath('uploads/links/' . $relative));
    }

}
