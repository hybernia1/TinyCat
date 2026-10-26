<?php
declare(strict_types=1);

namespace TinyCat\Support;

use RuntimeException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Config
{
    private static bool $booted = false;
    /** @var array<mixed> */
    private static array $config = [];

    public static function load(): void
    {
        if (self::$booted) {
            return;
        }

        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.php';

        if (!is_file($path)) {
            $loaded = [
                'install' => [
                    'locale' => 'en',
                    'complete' => false,
                ],
            ];
        } else {
            $loaded = require $path;

            if (!is_array($loaded)) {
                throw new RuntimeException('Config file must return an array.');
            }
        }

        self::$config = $loaded;
        self::$booted = true;
    }

    public static function get(?string $key = null, mixed $default = null): mixed
    {
        self::load();
        return $key === null || $key === '' ? self::$config : Values::get(self::$config, $key, $default);
    }
}
