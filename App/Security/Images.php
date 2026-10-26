<?php
declare(strict_types=1);

namespace TinyCat\Security;

use Throwable;
use RuntimeException;
use GdImage;

final class Images
{
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
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpName),
            'image/png' => @imagecreatefrompng($tmpName),
            'image/gif' => @imagecreatefromgif($tmpName),
            'image/webp' => @imagecreatefromwebp($tmpName),
            default => false,
        };

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Only JPEG, PNG, GIF, and WebP images can be uploaded.');
        }

        if ($mime === 'image/jpeg') {
            $source = \TinyCat\Security\Images::image_apply_orientation($source, $tmpName);
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

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent === false) { throw new RuntimeException('Could not allocate image color.'); }
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );

        $baseDirectory = \TinyCat\Support\Paths::basePath('uploads/site');
        $baseUrl = '/uploads/site';
        $subfolder = date('Y/m');
        $directory = $baseDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subfolder);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
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

        if (!imagewebp($canvas, $target, 86)) {
            imagedestroy($source);
            imagedestroy($canvas);
            throw new RuntimeException('Could not write WebP image.');
        }

        if ($variant === 'favicon') {
            try {
                \TinyCat\Site\Identity::writeVariants($source, $directory, pathinfo($filename, PATHINFO_FILENAME));
            } catch (Throwable $exception) {
                imagedestroy($source);
                imagedestroy($canvas);

                if (is_file($target)) {
                    @unlink($target);
                }

                throw $exception;
            }
        }

        imagedestroy($source);
        imagedestroy($canvas);

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
