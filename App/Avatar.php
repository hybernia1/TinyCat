<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Avatar
{
    private const SIZE = 200;
    private const QUALITY = 86;
    private const MAX_UPLOAD_SIZE = 10_485_760;
    private const MAX_SOURCE_DIMENSION = 8192;
    private const MAX_SOURCE_PIXELS = 16_777_216;
    private const BASE_DIRECTORY = 'uploads/avatars';
    private const BASE_URL = '/uploads/avatars';
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public static function url(int $userId, bool $exists, ?string $updatedAt = null): string
    {
        if ($userId < 1 || !$exists) {
            return '';
        }

        $version = trim((string) $updatedAt);

        return self::BASE_URL . '/' . $userId . '.webp' . ($version !== '' ? '?v=' . rawurlencode($version) : '');
    }

    public static function upload(array $file, int $userId): void
    {
        if ($userId < 1 || !extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('WebP image conversion is not available.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded avatar is not valid.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded avatar is not valid.');
        }

        clearstatcache(true, $tmpName);
        $actualSize = @filesize($tmpName);
        $info = @getimagesize($tmpName);
        $sourceError = image_source_error(
            $info,
            (int) ($file['size'] ?? 0),
            is_int($actualSize) ? $actualSize : 0,
            self::ALLOWED_MIMES,
            self::MAX_UPLOAD_SIZE,
            self::MAX_SOURCE_DIMENSION,
            self::MAX_SOURCE_PIXELS
        );

        if ($sourceError !== '') {
            throw new RuntimeException(match ($sourceError) {
                'size' => 'Avatar image is too large or has an invalid size.',
                'not_image', 'empty' => 'Uploaded avatar is not an image.',
                'mime' => 'Only JPEG, PNG, and WebP avatars are allowed.',
                default => 'Avatar image dimensions are too large.',
            });
        }

        if (!is_array($info)) {
            throw new RuntimeException('Uploaded avatar is not an image.');
        }

        $mime = strtolower((string) $info['mime']);

        $source = self::createSource($tmpName, $mime);

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Uploaded avatar could not be read.');
        }

        if ($mime === 'image/jpeg') {
            $source = image_apply_orientation($source, $tmpName);
        }

        $canvas = self::resizeSquare($source);
        imagedestroy($source);

        $directory = base_path(self::BASE_DIRECTORY);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create avatar directory.');
        }

        $temporary = tempnam($directory, 'avatar-');

        if ($temporary === false || !imagewebp($canvas, $temporary, self::QUALITY)) {
            imagedestroy($canvas);
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('Could not write avatar image.');
        }

        imagedestroy($canvas);
        $target = self::path($userId);

        if (!@rename($temporary, $target)) {
            if (!is_file($target) || !@unlink($target) || !@rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('Could not replace avatar image.');
            }
        }
    }

    public static function delete(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $file = self::path($userId);

        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    public static function respond(string $username): never
    {
        $username = strtolower(trim($username));

        if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username) !== 1 || !class_exists('Core')) {
            http_response_code(404);
            exit;
        }

        try {
            $user = Core::find('users', ['username' => $username]);
        } catch (Throwable) {
            $user = null;
        }

        $url = self::url((int) ($user['id'] ?? 0), (int) ($user['avatar_exists'] ?? 0) === 1, $user['updated_at'] ?? null);

        if ($url === '') {
            http_response_code(404);
            exit;
        }

        header('Location: ' . $url, true, 302);
        exit;
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

    private static function resizeSquare(GdImage $source): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $crop = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $crop) / 2);
        $sourceY = (int) floor(($sourceHeight - $crop) / 2);
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, $sourceX, $sourceY, self::SIZE, self::SIZE, $crop, $crop);

        return $canvas;
    }

    private static function path(int $userId): string
    {
        return base_path(self::BASE_DIRECTORY . '/' . $userId . '.webp');
    }
}
