<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class StatusImage
{
    private const BASE_DIRECTORY = 'uploads/status-images';
    private const BASE_URL = '/uploads/status-images';
    private const MAX_SOURCE_DIMENSION = 8192;
    private const MAX_SOURCE_PIXELS = 16_777_216;
    private const MAX_SOURCE_UPLOAD_SIZE = 10_485_760;
    private const MAX_DIMENSION = 800;
    private const ORPHAN_MINIMUM_AGE = 3600;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function upload(array $file): array
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('WebP image conversion is not available.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $maxSize = status_image_max_upload_bytes();

        if ($tmpName === '' || !is_uploaded_file($tmpName) || $size < 1 || $size > self::MAX_SOURCE_UPLOAD_SIZE) {
            throw new RuntimeException('Uploaded image is too large or not valid.');
        }

        $info = @getimagesize($tmpName);
        $mime = strtolower((string) ($info['mime'] ?? ''));

        if ($info === false || !in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('Only JPEG, PNG, and WebP images are allowed.');
        }

        self::assertSourceDimensions($info);
        $source = self::createSource($tmpName, $mime);

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Uploaded image could not be read.');
        }

        if ($mime === 'image/jpeg') {
            $source = self::applyOrientation($source, $tmpName);
        }

        $canvas = self::resize($source);
        imagedestroy($source);

        $yearMonth = date('Y/m');
        $directory = base_path(self::BASE_DIRECTORY . '/' . str_replace('/', DIRECTORY_SEPARATOR, $yearMonth));

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create image directory.');
        }

        $filename = bin2hex(random_bytes(16)) . '.webp';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $written = false;

        foreach ([82, 76, 70, 64, 58, 52, 46, 40] as $quality) {
            if (!imagewebp($canvas, $target, $quality)) {
                continue;
            }

            $written = true;
            if ((int) filesize($target) <= $maxSize) {
                break;
            }
        }

        imagedestroy($canvas);

        if (!$written || !is_file($target) || (int) filesize($target) > $maxSize) {
            if (is_file($target)) {
                @unlink($target);
            }
            throw new LengthException('The image could not be compressed to the configured limit.');
        }

        $relativePath = $yearMonth . '/' . $filename;
        $dimensions = @getimagesize($target) ?: [0, 0];

        return [
            'path' => $relativePath,
            'url' => self::url($relativePath),
            'width' => (int) ($dimensions[0] ?? 0),
            'height' => (int) ($dimensions[1] ?? 0),
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

        $directory = base_path(self::BASE_DIRECTORY . ($relativeDirectory !== '' ? '/' . $relativeDirectory : ''));
        $pathPrefix = $relativeDirectory !== '' ? $relativeDirectory . '/' : '';

        if (!is_dir($directory)) {
            return ['changed' => 0, 'has_more' => false];
        }

        $cutoff = time() - self::ORPHAN_MINIMUM_AGE;
        $changed = 0;
        $pending = [];
        $scanBatch = min(1000, max(100, $limit));
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $flush = static function (array $paths) use (&$changed, $limit): bool {
            if ($paths === []) {
                return false;
            }

            $placeholders = implode(', ', array_fill(0, count($paths), '?'));
            $records = Core::all('SELECT path FROM content_images WHERE path IN (' . $placeholders . ')', array_keys($paths));
            $stored = [];

            foreach ($records as $record) {
                $stored[(string) ($record['path'] ?? '')] = true;
            }

            foreach ($paths as $path => $file) {
                if (isset($stored[$path])) {
                    continue;
                }

                if ($changed >= $limit) {
                    return true;
                }

                if (self::deletePath($path)) {
                    $changed++;
                }
            }

            return false;
        };

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getMTime() > $cutoff) {
                continue;
            }

            $relativePath = $pathPrefix . str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $relativePath = self::normalizePath($relativePath);

            if ($relativePath === '') {
                continue;
            }

            $pending[$relativePath] = $file->getPathname();

            if (count($pending) >= $scanBatch) {
                if ($flush($pending)) {
                    return ['changed' => $changed, 'has_more' => true];
                }

                $pending = [];
            }
        }

        if ($flush($pending)) {
            return ['changed' => $changed, 'has_more' => true];
        }

        return ['changed' => $changed, 'has_more' => $changed >= $limit];
    }

    private static function createSource(string $path, string $mime): GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
    }

    private static function assertSourceDimensions(array $info): void
    {
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width < 1 || $height < 1 || $width > self::MAX_SOURCE_DIMENSION || $height > self::MAX_SOURCE_DIMENSION || $height > intdiv(self::MAX_SOURCE_PIXELS, $width)) {
            throw new RuntimeException('Image dimensions are too large.');
        }
    }

    private static function resize(GdImage $source): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $canvas = imagecreatetruecolor($width, $height);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        return $canvas;
    }

    private static function applyOrientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotate = static function (GdImage $source, int $angle): GdImage {
            $rotated = imagerotate($source, $angle, 0);
            return $rotated instanceof GdImage ? $rotated : $source;
        };
        $flip = static function (GdImage $source, int $mode): GdImage {
            if (function_exists('imageflip')) {
                imageflip($source, $mode);
            }
            return $source;
        };

        return match ($orientation) {
            2 => $flip($image, IMG_FLIP_HORIZONTAL),
            3 => $rotate($image, 180),
            4 => $flip($image, IMG_FLIP_VERTICAL),
            5 => $flip($rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $rotate($image, -90),
            7 => $flip($rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $rotate($image, 90),
            default => $image,
        };
    }

    private static function absolutePath(string $path): string
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            return '';
        }

        $base = realpath(base_path(self::BASE_DIRECTORY));
        $file = base_path(self::BASE_DIRECTORY . '/' . $path);
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
