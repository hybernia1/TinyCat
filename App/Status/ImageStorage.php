<?php
declare(strict_types=1);

namespace TinyCat\Status;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ImageStorage
{
    private const BASE_DIRECTORY = 'uploads/status-images';
    private const BASE_URL = '/uploads/status-images';
    private const MAX_SOURCE_DIMENSION = 8192;
    private const MAX_SOURCE_PIXELS = 16_777_216;
    private const MAX_SOURCE_UPLOAD_SIZE = 10_485_760;
    private const MAX_DIMENSION = 800;
    private const ORPHAN_MINIMUM_AGE = 3600;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array<mixed>
     * @param array<mixed> $file
     */
    public static function upload(array $file): array
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('WebP image conversion is not available.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $maxSize = \TinyCat\Status\Images::status_image_max_upload_bytes();

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded image is too large or not valid.');
        }

        clearstatcache(true, $tmpName);
        $actualSize = @filesize($tmpName);
        $info = @getimagesize($tmpName);
        $sourceError = \TinyCat\Security\Images::image_source_error(
            $info,
            (int) ($file['size'] ?? 0),
            is_int($actualSize) ? $actualSize : 0,
            self::ALLOWED_MIMES,
            self::MAX_SOURCE_UPLOAD_SIZE,
            self::MAX_SOURCE_DIMENSION,
            self::MAX_SOURCE_PIXELS
        );

        if ($sourceError !== '') {
            throw new RuntimeException(match ($sourceError) {
                'size' => 'Uploaded image is too large or has an invalid size.',
                'not_image', 'mime' => 'Only JPEG, PNG, and WebP images are allowed.',
                default => 'Image dimensions are too large.',
            });
        }

        if (!is_array($info)) {
            throw new RuntimeException('Only JPEG, PNG, and WebP images are allowed.');
        }

        $mime = strtolower((string) $info['mime']);
        $source = \TinyCat\Security\Images::image_open($tmpName, $mime);

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Uploaded image could not be read.');
        }

        try { $canvas = self::resize($source); }
        finally { imagedestroy($source); }

        $yearMonth = date('Y/m');
        $directory = \TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY . '/' . str_replace('/', DIRECTORY_SEPARATOR, $yearMonth));

        if (!\TinyCat\Support\Storage::ensureDirectory($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create image directory.');
        }

        $filename = bin2hex(random_bytes(16)) . '.webp';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        try {
            \TinyCat\Security\Images::image_write_webp($canvas, $target, [82, 76, 70, 64, 58, 52, 46, 40], $maxSize);
        } finally {
            imagedestroy($canvas);
        }

        $relativePath = $yearMonth . '/' . $filename;
        $dimensions = @getimagesize($target) ?: [0, 0];

        return [
            'path' => $relativePath,
            'url' => self::url($relativePath),
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
            'bytes' => (int) filesize($target),
        ];
    }

    public static function url(string $path): string
    {
        $path = self::normalizePath($path);

        return $path === '' ? '' : self::BASE_URL . '/' . $path;
    }

    public static function delete(string $path): void
    {
        self::deletePath($path);
    }

    /**
     * Removes WebP files without a content_images record. Files created in the
     * last hour are deliberately left alone so an in-flight upload cannot race
     * the scheduled cleanup.
     *
     * @return array{changed: int, has_more: bool}
     */
    public static function cleanupOrphans(int $limit, string $relativeDirectory = ''): array
    {
        $limit = max(1, $limit);
        $relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');

        if ($relativeDirectory !== '' && preg_match('~^\d{4}/\d{2}$~D', $relativeDirectory) !== 1) {
            throw new InvalidArgumentException('Invalid status image cleanup directory.');
        }

        $directory = \TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY . ($relativeDirectory !== '' ? '/' . $relativeDirectory : ''));
        $pathPrefix = $relativeDirectory !== '' ? $relativeDirectory . '/' : '';

        if (!is_dir($directory)) {
            return ['changed' => 0, 'has_more' => false];
        }

        return \TinyCat\Support\FileSweep::run($directory, $limit, static function (array $files) use ($pathPrefix, $directory): array {
            $paths = [];
            foreach ($files as $file) {
                if ($file->getMTime() > time() - self::ORPHAN_MINIMUM_AGE) { continue; }
                $relative = self::normalizePath($pathPrefix . str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1)));
                if ($relative !== '') { $paths[$relative] = $file->getPathname(); }
            }
            $stored = [];
            foreach (array_chunk(array_keys($paths), 100) as $chunk) {
                $rows = \TinyCat\Database\Connection::all('SELECT path FROM content_images WHERE path IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk);
                foreach ($rows as $row) { $stored[(string) $row['path']] = true; }
            }
            $changed = 0;
            foreach ($paths as $relative => $path) {
                clearstatcache(true, $path);
                $modified = @filemtime($path);
                if (!isset($stored[$relative]) && $modified !== false && $modified <= time() - self::ORPHAN_MINIMUM_AGE && !is_link($path) && self::deletePath($relative)) { $changed++; }
            }
            return ['changed' => $changed, 'has_more' => false];
        });
    }

    private static function resize(GdImage $source): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        return \TinyCat\Security\Images::image_resample($source, $width, $height);
    }

    private static function absolutePath(string $path): string
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            return '';
        }

        $base = realpath(\TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY));
        $file = \TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY . '/' . $path);
        $directory = realpath(dirname($file));

        return $base !== false && $directory !== false && str_starts_with($directory, $base) ? $file : '';
    }

    private static function deletePath(string $path): bool
    {
        $file = self::absolutePath($path);

        return $file !== '' && (!is_file($file) || @unlink($file));
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        return preg_match('~^\d{4}/\d{2}/[a-f0-9]{32}\.webp$~D', $path) === 1 ? $path : '';
    }
}
