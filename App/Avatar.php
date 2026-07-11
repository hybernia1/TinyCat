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
    private const BASE_DIRECTORY = 'uploads/avatars';
    private const BASE_URL = '/uploads/avatars';
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public static function url(string $username, array|string|null $config = null): string
    {
        $config = self::normalizeConfig($config);
        $url = (string) ($config['url'] ?? '');

        if ($url === '') {
            return '';
        }

        $version = (string) ($config['hash'] ?? '');

        return $url . ($version !== '' ? '?v=' . rawurlencode($version) : '');
    }

    public static function normalizeConfig(array|string|null $config): array
    {
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($config)) {
            return [];
        }

        $path = trim((string) ($config['path'] ?? ''));
        $url = trim((string) ($config['url'] ?? ''));

        if ($path === '' || $url === '') {
            return [];
        }

        $path = str_replace('\\', '/', $path);

        if (
            str_contains($path, '..')
            || !preg_match('~^[a-z0-9/_-]+\.webp$~i', $path)
            || !str_starts_with($url, self::BASE_URL . '/')
        ) {
            return [];
        }

        return [
            'path' => $path,
            'url' => $url,
            'hash' => preg_replace('/[^a-f0-9]/i', '', (string) ($config['hash'] ?? '')) ?: '',
            'size' => max(0, (int) ($config['size'] ?? 0)),
            'width' => self::SIZE,
            'height' => self::SIZE,
        ];
    }

    public static function configJson(array|string|null $config): string
    {
        $config = self::normalizeConfig($config);

        if ($config === []) {
            return '';
        }

        try {
            return json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }
    }

    public static function upload(array $file, string $username = ''): array
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('WebP image conversion is not available.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded avatar is not valid.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded avatar is not valid.');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size < 1 || $size > self::MAX_UPLOAD_SIZE) {
            throw new RuntimeException('Avatar image is too large.');
        }

        $info = @getimagesize($tmpName);

        if ($info === false || empty($info['mime'])) {
            throw new RuntimeException('Uploaded avatar is not an image.');
        }

        $mime = strtolower((string) $info['mime']);

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('Only JPEG, PNG, and WebP avatars are allowed.');
        }

        $source = self::createSource($tmpName, $mime);

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Uploaded avatar could not be read.');
        }

        if ($mime === 'image/jpeg') {
            $source = self::applyOrientation($source, $tmpName);
        }

        $canvas = self::resizeSquare($source);
        imagedestroy($source);

        $username = self::username($username) ?: 'avatar';
        $hash = substr(hash('sha256', $username . '|' . microtime(true) . '|' . bin2hex(random_bytes(16))), 0, 16);
        $folder = substr($hash, 0, 2);
        $directory = base_path(self::BASE_DIRECTORY . '/' . $folder);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create avatar directory.');
        }

        $filename = $username . '-' . $hash . '.webp';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;

        if (!imagewebp($canvas, $target, self::QUALITY)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not write avatar image.');
        }

        imagedestroy($canvas);

        $relativePath = $folder . '/' . $filename;
        $newConfig = [
            'path' => $relativePath,
            'url' => self::BASE_URL . '/' . $relativePath,
            'hash' => substr(hash_file('sha256', $target) ?: $hash, 0, 16),
            'size' => (int) filesize($target),
            'width' => self::SIZE,
            'height' => self::SIZE,
        ];

        return $newConfig;
    }

    public static function delete(array|string|null $config, array|string|null $except = null): void
    {
        $config = self::normalizeConfig($config);
        $except = self::normalizeConfig($except);
        $path = (string) ($config['path'] ?? '');

        if ($path === '' || ($except !== [] && $path === (string) ($except['path'] ?? ''))) {
            return;
        }

        $file = self::absolutePath($path);

        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    public static function respond(string $username): never
    {
        $username = self::username($username);

        if ($username === '' || !class_exists('Core')) {
            http_response_code(404);
            exit;
        }

        try {
            $user = Core::find('users', ['username' => $username]);
        } catch (Throwable) {
            $user = null;
        }

        $url = self::url($username, $user['avatar_config'] ?? null);

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
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
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
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_contains($path, '..') || !preg_match('~^[a-z0-9/_-]+\.webp$~i', $path)) {
            return '';
        }

        $base = realpath(base_path(self::BASE_DIRECTORY));

        if ($base === false) {
            return '';
        }

        $file = base_path(self::BASE_DIRECTORY . '/' . $path);
        $directory = realpath(dirname($file));

        if ($directory === false || !str_starts_with($directory, $base)) {
            return '';
        }

        return $file;
    }

    private static function username(string $username): string
    {
        $username = strtolower(trim($username));

        return preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username) === 1 ? $username : '';
    }
}
