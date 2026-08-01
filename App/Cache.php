<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Shared filesystem cache for computed data and generated files.
 *
 * All persistent cache content lives below storage/cache. JSON entries use the
 * cache root; generated files can be separated into a named namespace.
 */
final class Cache
{
    private function __construct()
    {
    }

    public static function get(string $key, int $ttl = 300): mixed
    {
        return self::fresh($key, $ttl) ? self::read($key) : null;
    }

    public static function read(string $key): mixed
    {
        $file = self::jsonFile($key);

        if (!is_file($file)) {
            return null;
        }

        $json = file_get_contents($file);

        if (!is_string($json) || $json === '') {
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    public static function put(string $key, mixed $value): bool
    {
        try {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return false;
        }

        return self::writeFile(self::jsonFileName($key), $json);
    }

    public static function fresh(string $key, int $ttl = 300): bool
    {
        $file = self::jsonFile($key);
        clearstatcache(true, $file);
        $modifiedAt = is_file($file) ? filemtime($file) : false;

        return is_int($modifiedAt) && $modifiedAt >= time() - max(1, $ttl);
    }

    public static function forget(string $key): bool
    {
        $file = self::jsonFile($key);

        return !is_file($file) || @unlink($file);
    }

    public static function file(string $fileName, string $namespace = ''): string
    {
        if (
            preg_match('/^[A-Za-z0-9._-]+$/', $fileName) !== 1
            || $fileName === '.'
            || $fileName === '..'
        ) {
            throw new InvalidArgumentException('Invalid cache file name.');
        }

        return self::directory($namespace) . DIRECTORY_SEPARATOR . $fileName;
    }

    public static function directory(string $namespace = ''): string
    {
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        if ($namespace === '') {
            return $directory;
        }

        foreach (explode('/', str_replace('\\', '/', $namespace)) as $segment) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $segment) !== 1) {
                throw new InvalidArgumentException('Invalid cache namespace.');
            }

            $directory .= DIRECTORY_SEPARATOR . $segment;
        }

        return $directory;
    }

    public static function writeFile(string $fileName, string $content, string $namespace = ''): bool
    {
        $directory = self::directory($namespace);
        $target = self::file($fileName, $namespace);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = tempnam($directory, '.tinycat-cache-');

        if ($temporary === false) {
            return false;
        }

        $written = file_put_contents($temporary, $content, LOCK_EX);

        if ($written === false) {
            @unlink($temporary);
            return false;
        }

        @chmod($temporary, 0664);

        if (@rename($temporary, $target)) {
            return true;
        }

        // Content-addressed files may have been created by another request.
        if (is_file($target) && @file_get_contents($target) === $content) {
            @unlink($temporary);
            return true;
        }

        // Windows cannot replace an existing file with rename(). A cache miss
        // during this very short replacement window is safe and recomputable.
        if (is_file($target) && @unlink($target) && @rename($temporary, $target)) {
            return true;
        }

        @unlink($temporary);
        return false;
    }

    /**
     * Removes files in a namespace selected by a caller-provided predicate.
     */
    public static function prune(string $namespace, callable $shouldRemove): int
    {
        $directory = self::directory($namespace);
        $entries = is_dir($directory) ? scandir($directory) : false;

        if (!is_array($entries)) {
            return 0;
        }

        $removed = 0;

        foreach ($entries as $fileName) {
            if ($fileName === '.' || $fileName === '..' || !$shouldRemove($fileName)) {
                continue;
            }

            $file = $directory . DIRECTORY_SEPARATOR . $fileName;

            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private static function jsonFile(string $key): string
    {
        return self::file(self::jsonFileName($key));
    }

    private static function jsonFileName(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $key) ?: 'cache';

        return $safe . '.json';
    }
}
