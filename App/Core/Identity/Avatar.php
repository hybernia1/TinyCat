<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

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

    public static function url(int $userId, bool $exists, ?string $updatedAt = null, ?string $appearance = null, ?string $uploadVersion = null): string
    {
        if ($userId < 1) { return ''; }
        if (!$exists) { return GeneratedAvatar::url($userId, $appearance); }

        if (is_string($uploadVersion) && preg_match('/^[a-f0-9]{32}$/D', $uploadVersion) === 1) { return self::BASE_URL . '/' . $userId . '-' . $uploadVersion . '.webp'; }
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
        $sourceError = \TinyCat\Core\Security\Images::image_source_error(
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

        $source = \TinyCat\Core\Security\Images::image_open($tmpName, $mime);

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Uploaded avatar could not be read.');
        }

        try { $canvas = self::resizeSquare($source); }
        finally { imagedestroy($source); }

        $directory = \TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY);

        if (!\TinyCat\Support\Storage::ensureDirectory($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create avatar directory.');
        }

        $temporary = @tempnam($directory, '.avatar-');
        if ($temporary === false) { imagedestroy($canvas); throw new RuntimeException('Could not stage avatar.'); }
        // The shared writer publishes to a fresh path on Windows as well as Unix.
        if (!@unlink($temporary)) { imagedestroy($canvas); throw new RuntimeException('Could not stage avatar.'); }
        try {
            \TinyCat\Core\Security\Images::image_write_webp($canvas, $temporary, [self::QUALITY]);
            return $temporary;
        } finally {
            imagedestroy($canvas);
        }
    }

    /** Publish an immutable file before switching its database reference atomically.
     * A crash before commit leaves an aged orphan, never a changed current file.
     * @param callable(array<string, mixed>): array<string, mixed> $persist
     * @return array<string, mixed> */
    public static function replace(int $userId, ?string $prepared, bool $remove, callable $persist): array
    {
        $database = \TinyCat\Database\Connection::get();
        $target = null;
        $oldPath = null;
        $oldVersion = null;
        $committed = false;
        $ownsTransaction = false;
        $published = false;
        try {
            if ($userId < 1 || $database->inTransaction()) { throw new \LogicException('Avatar replacement requires its own transaction and a valid user.'); }
            $version = $prepared !== null ? bin2hex(random_bytes(16)) : null;
            if ($prepared !== null) {
                $target = self::path($userId, $version);
                // Exclusive creation never overwrites another upload and needs no hard-link support.
                $input = @fopen($prepared, 'rb');
                if ($input === false) { throw new RuntimeException('Could not read prepared avatar.'); }
                $output = @fopen($target, 'x');
                if ($output === false) { fclose($input); throw new RuntimeException('Could not publish immutable avatar.'); }
                $published = true;
                try {
                    $info = fstat($input);
                    $copied = stream_copy_to_stream($input, $output);
                    if ($info === false || $copied === false || $copied !== $info['size'] || !fflush($output)) { throw new RuntimeException('Could not finish immutable avatar.'); }
                } finally { fclose($input); fclose($output); }
            }
            $database->beginTransaction();
            $ownsTransaction = true;
            $user = \TinyCat\Database\Connection::one(\TinyCat\Database\Connection::lockingSelect('SELECT * FROM users WHERE id = ?'), [$userId]);
            if ($user === null) { throw new RuntimeException('Avatar owner no longer exists.'); }
            $updated = $persist($user);
            if ($prepared !== null || $remove) {
                $changes = ['avatar_exists' => $prepared !== null ? 1 : 0, 'avatar_version' => $version, 'updated_at' => Avatars::user_avatar_updated_at($user)];
                \TinyCat\Database\Connection::update('users', $changes, ['id' => $userId]);
                $updated = array_replace($updated, $changes);
                $oldVersion = $user['avatar_version'] ?? null;
                $oldPath = self::path($userId, $oldVersion);
            }
            $database->commit();
            $committed = true;
        } finally {
            if ($ownsTransaction && !$committed && $database->inTransaction()) { $database->rollBack(); }
            if (!$committed && $published && is_string($target) && is_file($target)) { @unlink($target); }
            if ($prepared !== null && is_file($prepared)) { @unlink($prepared); }
        }
        // No cleanup failure can report an unsuccessful committed avatar write.
        if ($oldPath !== null) {
            try { self::delete($userId, $oldVersion); }
            catch (Throwable $error) { \TinyCat\Core\Runtime\Failure::report($error); }
        }
        return $updated;
    }

    public static function delete(int $userId, ?string $version = null): void
    {
        if ($userId < 1) { return; }
        if (\TinyCat\Database\Connection::get()->inTransaction()) { throw new \LogicException('Avatar deletion requires a committed reference view.'); }
        $current = \TinyCat\Database\Connection::one('SELECT avatar_exists, avatar_version FROM users WHERE id = ?', [$userId]);
        if (!self::retains($current, $version)) { self::removeFile(self::path($userId, $version)); }
    }

    /** @param array<mixed>|null $user */
    private static function retains(?array $user, ?string $version): bool
    {
        return $user !== null && (int) $user['avatar_exists'] === 1 && ($user['avatar_version'] ?? null) === $version;
    }

    private static function removeFile(string $path): void
    {
        if (is_link($path)) { throw new RuntimeException('Refusing an avatar symlink.'); }
        if (is_file($path) && !@unlink($path)) { throw new RuntimeException('Could not remove superseded avatar.'); }
    }

    private static function resizeSquare(GdImage $source): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $crop = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $crop) / 2);
        $sourceY = (int) floor(($sourceHeight - $crop) / 2);
        return \TinyCat\Core\Security\Images::image_resample($source, self::SIZE, self::SIZE, $sourceX, $sourceY, $crop, $crop);
    }

    /** @return array{changed: int, has_more: bool} */
    public static function cleanupOrphans(int $limit): array
    {
        return \TinyCat\Support\FileSweep::run(\TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY), $limit, static function (array $files): array {
            $candidates = []; $ids = [];
            foreach ($files as $file) {
                if (preg_match('/^([1-9][0-9]*)(?:-([a-f0-9]{32}))?\.webp$/D', $file->getFilename(), $match) === 1 && $file->getMTime() < time() - 86400) {
                    $candidates[] = [$file->getPathname(), (int) $match[1], $match[2] ?? null];
                    $ids[(int) $match[1]] = (int) $match[1];
                }
            }
            $owners = [];
            foreach (array_chunk(array_values($ids), 100) as $batch) {
                foreach (\TinyCat\Database\Connection::all('SELECT id, avatar_exists, avatar_version FROM users WHERE id IN (' . implode(', ', array_fill(0, count($batch), '?')) . ')', $batch) as $row) { $owners[(int) $row['id']] = $row; }
            }
            $changed = 0;
            foreach ($candidates as [$path, $id, $version]) {
                $owner = $owners[$id] ?? null;
                $retained = self::retains($owner, $version);
                clearstatcache(true, $path); $modified = @filemtime($path);
                if (!$retained && $modified !== false && $modified < time() - 86400 && \TinyCat\Support\FileSweep::remove($path)) { $changed++; }
            }
            return ['changed' => $changed, 'has_more' => false];
        });
    }

    private static function path(int $userId, ?string $version = null): string
    {
        if ($version !== null && preg_match('/^[a-f0-9]{32}$/D', $version) !== 1) { throw new RuntimeException('Invalid avatar version.'); }
        return \TinyCat\Support\Paths::basePath(self::BASE_DIRECTORY . '/' . $userId . ($version !== null ? '-' . $version : '') . '.webp');
    }
}
