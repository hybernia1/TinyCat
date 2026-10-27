<?php
declare(strict_types=1);

namespace TinyCat\Users;

use GdImage;
use Throwable;
use RuntimeException;

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

    /**
     * @param array<mixed> $file
     */
    public static function prepare(array $file): string
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

        clearstatcache(true, $tmpName);
        $actualSize = @filesize($tmpName);
        $info = @getimagesize($tmpName);
        $sourceError = \TinyCat\Security\Images::image_source_error(
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

        $source = \TinyCat\Security\Images::image_open($tmpName, $mime);

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Uploaded avatar could not be read.');
        }

        try { $canvas = self::resizeSquare($source); }
        finally { imagedestroy($source); }

        $directory = \TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create avatar directory.');
        }

        $temporary = @tempnam($directory, '.avatar-');
        if ($temporary === false) { imagedestroy($canvas); throw new RuntimeException('Could not stage avatar.'); }
        // The shared writer publishes to a fresh path on Windows as well as Unix.
        if (!@unlink($temporary)) { imagedestroy($canvas); throw new RuntimeException('Could not stage avatar.'); }
        try {
            \TinyCat\Security\Images::image_write_webp($canvas, $temporary, [self::QUALITY]);
            return $temporary;
        } finally {
            imagedestroy($canvas);
        }
    }

    /**
     * Keep the old file until both replacement and database persistence succeed.
     * The user row lock serializes all avatar changes, including removal.
     * @param callable(array<string, mixed>): array<string, mixed> $persist
     * @return array<string, mixed>
     */
    public static function replace(int $userId, ?string $prepared, bool $remove, callable $persist): array
    {
        $database = \TinyCat\Database\Connection::get();
        $target = self::path($userId);
        $backup = null;
        $fileChanged = false;
        $ownsTransaction = false;
        $preserveBackup = false;
        try {
            if ($userId < 1 || $database->inTransaction()) { throw new \LogicException('Avatar replacement requires its own transaction and a valid user.'); }
            $database->beginTransaction();
            $ownsTransaction = true;
            $user = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM users WHERE id = ?'), [$userId]);
            if ($user === null) { throw new RuntimeException('Avatar owner no longer exists.'); }
            if (($prepared !== null || $remove) && is_file($target)) {
                $backup = @tempnam(dirname($target), '.avatar-backup-');
                if ($backup === false || !@copy($target, $backup)) { throw new RuntimeException('Could not back up avatar.'); }
            }
            if ($prepared !== null) {
                if (!@rename($prepared, $target)) {
                    if (!is_file($target) || !@unlink($target)) { throw new RuntimeException('Could not replace avatar.'); }
                    $fileChanged = true;
                    if (!@rename($prepared, $target)) { throw new RuntimeException('Could not replace avatar.'); }
                }
                $fileChanged = true;
            } elseif ($remove && is_file($target)) {
                if (!@unlink($target)) { throw new RuntimeException('Could not remove avatar.'); }
                $fileChanged = true;
            }
            $updated = $persist($user);
            $database->commit();
            return $updated;
        } catch (Throwable $exception) {
            // Restore while still holding the row lock, before allowing another save.
            if ($fileChanged) {
                if (is_string($backup)) {
                    if (is_file($target)) { @unlink($target); }
                    if (!@rename($backup, $target)) {
                        $preserveBackup = true;
                        throw new RuntimeException('Could not restore avatar backup: ' . $backup, 0, $exception);
                    }
                    $backup = null;
                } elseif (is_file($target)) { @unlink($target); }
            }
            throw $exception;
        } finally {
            if ($ownsTransaction && $database->inTransaction()) { $database->rollBack(); }
            if (!$preserveBackup && is_string($backup) && is_file($backup)) { @unlink($backup); }
            if ($prepared !== null && is_file($prepared)) { @unlink($prepared); }
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
        $username = \TinyCat\Users\Usernames::username_normalize($username);

        if (!\TinyCat\Users\Usernames::username_valid($username)) {
            \TinyCat\Site\Errors::respond(404);
            exit;
        }

        try {
            $user = \TinyCat\Database\Connection::find('users', ['username' => $username]);
        } catch (Throwable) {
            $user = null;
        }

        $url = self::url((int) ($user['id'] ?? 0), (int) ($user['avatar_exists'] ?? 0) === 1, $user['updated_at'] ?? null);

        if ($url === '') {
            \TinyCat\Site\Errors::respond(404);
            exit;
        }

        header('Location: ' . $url, true, 302);
        exit;
    }

    private static function resizeSquare(GdImage $source): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $crop = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $crop) / 2);
        $sourceY = (int) floor(($sourceHeight - $crop) / 2);
        return \TinyCat\Security\Images::image_resample($source, self::SIZE, self::SIZE, $sourceX, $sourceY, $crop, $crop);
    }

    private static function path(int $userId): string
    {
        return \TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY . '/' . $userId . '.webp');
    }
}
