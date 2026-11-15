<?php
declare(strict_types=1);

namespace TinyCat\Tools;

/** Private development outputs; never loaded or distributed by the application. */
final class Workspace
{
    public static function directory(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $relative) !== 1) {
            throw new \InvalidArgumentException('Invalid development workspace category.');
        }
        $path = dirname(__DIR__, 2) . '/.work/' . $relative;
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create development workspace.');
        }
        return $path;
    }
}
