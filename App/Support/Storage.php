<?php
declare(strict_types=1);

namespace TinyCat\Support;

use InvalidArgumentException;

/** Shared filesystem primitives. Domain owners retain formats and lifetimes. */
final class Storage
{
    public static function path(string $relative = ''): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative !== '' && (preg_match('~^[A-Za-z0-9._/-]+$~D', $relative) !== 1
            || array_any(explode('/', $relative), static fn (string $part): bool => $part === '' || $part === '.' || $part === '..')
        )) {
            throw new InvalidArgumentException('Invalid storage path.');
        }
        return Paths::basePath('storage' . ($relative === '' ? '' : '/' . $relative));
    }

    public static function ensureDirectory(string $directory, int $mode = 0775): bool
    {
        return is_dir($directory) || @mkdir($directory, $mode, true) || is_dir($directory);
    }

    /**
     * Write beside the destination, then publish by rename. Only disposable
     * caches may opt into an unlink fallback on Windows; state must stay intact
     * if replacement fails. The caller owns validation of the absolute target.
     */
    public static function write(string $target, string $content, int $mode = 0600, bool $replaceDisposable = false): bool
    {
        $directory = dirname($target);
        if (!self::ensureDirectory($directory)) { return false; }
        $temporary = @tempnam($directory, '.tinycat-write-');
        if ($temporary === false) { return false; }
        try {
            if (@file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) { return false; }
            if (!@chmod($temporary, $mode)) { return false; }
            if (@rename($temporary, $target)) { return true; }
            if (is_file($target) && @file_get_contents($target) === $content) { return true; }
            return $replaceDisposable && is_file($target) && @unlink($target) && @rename($temporary, $target);
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }
}
