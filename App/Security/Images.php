<?php
declare(strict_types=1);

namespace TinyCat\Security;

use Throwable;
use RuntimeException;
use GdImage;

final class Images
{
    /** Decode only after checking source metadata and resource limits. */
    public static function image_open(string $path, string $mime): GdImage|false
    {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        return $source instanceof GdImage && $mime === 'image/jpeg' ? self::image_apply_orientation($source, $path) : $source;
    }

    public static function image_resample(GdImage $source, int $width, int $height, int $sourceX = 0, int $sourceY = 0, ?int $cropWidth = null, ?int $cropHeight = null): GdImage
    {
        if ($width < 1 || $height < 1) { throw new \InvalidArgumentException('Image dimensions must be positive.'); }
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent === false || !imagefill($canvas, 0, 0, $transparent)
            || !imagecopyresampled($canvas, $source, 0, 0, $sourceX, $sourceY, $width, $height, $cropWidth ?? imagesx($source), $cropHeight ?? imagesy($source))) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not resize image.');
        }
        return $canvas;
    }

    /** @param list<int> $qualities */
    public static function image_write_webp(GdImage $image, string $target, array $qualities = [86], int $maxBytes = 0, bool $keepExisting = false): void
    {
        if (!is_dir(dirname($target))) { throw new RuntimeException('Image directory does not exist.'); }
        $temporary = @tempnam(dirname($target), '.image-');
        if ($temporary === false) { throw new RuntimeException('Could not create temporary image.'); }
        try {
            $written = false;
            $fits = false;
            foreach ($qualities as $quality) {
                if (!@imagewebp($image, $temporary, $quality)) { continue; }
                clearstatcache(true, $temporary);
                $bytes = filesize($temporary);
                $written = is_int($bytes) && $bytes > 0;
                $fits = $written && ($maxBytes < 1 || $bytes <= $maxBytes);
                if ($fits) { break; }
            }
            if (!$written) { throw new RuntimeException('Could not write WebP image.'); }
            if (!$fits) { throw new \LengthException('The image could not be compressed to the configured limit.'); }
            if ($keepExisting && is_file($target)) { return; }
            if (!@rename($temporary, $target)) {
                if ($keepExisting && is_file($target)) { return; }
                throw new RuntimeException('Could not publish WebP image.');
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    /**
     * @param array<mixed> $allowedMimes
     * @param array<mixed> $info
     */
    public static function image_source_error(
        array|false $info,
        int $reportedBytes,
        int $actualBytes,
        array $allowedMimes,
        int $maxBytes,
        int $maxDimension,
        int $maxPixels
    ): string {
        if ($reportedBytes < 1 || $actualBytes < 1 || $reportedBytes !== $actualBytes || $actualBytes > $maxBytes) {
            return 'size';
        }

        if (!is_array($info) || empty($info['mime'])) {
            return 'not_image';
        }

        if (!in_array(strtolower((string) $info['mime']), $allowedMimes, true)) {
            return 'mime';
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width < 1 || $height < 1) {
            return 'empty';
        }

        if (
            $width > $maxDimension
            || $height > $maxDimension
            || $height > intdiv($maxPixels, $width)
        ) {
            return 'dimensions';
        }

        return '';
    }

    /**
     * @param array<mixed> $file
     * @return array<mixed>
     */
    public static function site_image_upload(array $file, string $name, string $variant): array
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('WebP image conversion is not available.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        clearstatcache(true, $tmpName);
        $actualSize = @filesize($tmpName);
        $info = @getimagesize($tmpName);
        $sourceError = \TinyCat\Security\Images::image_source_error(
            $info,
            (int) ($file['size'] ?? 0),
            is_int($actualSize) ? $actualSize : 0,
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            64 * 1024 * 1024,
            8192,
            16_777_216
        );

        if ($sourceError !== '') {
            throw new RuntimeException(match ($sourceError) {
                'size' => 'Uploaded image is too large or has an invalid size.',
                'empty' => 'Uploaded image is empty.',
                'dimensions' => 'Uploaded image dimensions are too large.',
                default => 'Only JPEG, PNG, GIF, and WebP images can be uploaded.',
            });
        }

        if (!is_array($info)) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        $mime = strtolower((string) $info['mime']);
        $source = self::image_open($tmpName, $mime);

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Only JPEG, PNG, GIF, and WebP images can be uploaded.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);


        if ($variant === 'favicon') {
            $targetWidth = 64;
            $targetHeight = 64;
            $cropSize = min($sourceWidth, $sourceHeight);
            $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
            $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);
            $cropWidth = $cropSize;
            $cropHeight = $cropSize;
        } else {
            $maxWidth = 640;
            $scale = $sourceWidth > $maxWidth ? $maxWidth / $sourceWidth : 1.0;
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
            $sourceX = 0;
            $sourceY = 0;
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceHeight;
        }

        $canvas = self::image_resample($source, $targetWidth, $targetHeight, $sourceX, $sourceY, $cropWidth, $cropHeight);

        $baseDirectory = \TinyCat\Support\Paths::basePath('uploads/site');
        $baseUrl = '/uploads/site';
        $subfolder = date('Y/m');
        $directory = $baseDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subfolder);

        if (!\TinyCat\Support\Storage::ensureDirectory($directory)) {
            imagedestroy($source);
            imagedestroy($canvas);
            throw new RuntimeException('Could not create image directory.');
        }

        $base = \TinyCat\Support\Text::slug($name);
        $base = $base !== '' ? $base : $variant;
        $filename = $base . '.webp';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $counter = 2;

        while (is_file($target)) {
            $filename = $base . '-' . $counter . '.webp';
            $target = $directory . DIRECTORY_SEPARATOR . $filename;
            $counter++;
        }

        try {
            self::image_write_webp($canvas, $target);
            if ($variant === 'favicon') {
                \TinyCat\Site\Identity::writeVariants($source, $directory, pathinfo($filename, PATHINFO_FILENAME));
            }
        } catch (Throwable $exception) {
            if (is_file($target)) { @unlink($target); }
            throw $exception;
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }

        return [
            'path' => trim($subfolder . '/' . $filename, '/'),
            'url' => $baseUrl . '/' . $subfolder . '/' . $filename,
            'size' => (int) filesize($target),
        ];
    }

    public static function image_apply_orientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotate = static function (GdImage $source, int $angle): GdImage {
            $rotated = imagerotate($source, $angle, 0);

            if (!$rotated instanceof GdImage) {
                return $source;
            }

            imagedestroy($source);
            return $rotated;
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
}
