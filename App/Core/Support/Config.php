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

    public static function configure(\TinyCat\Core\Runtime\Configuration $configuration): void
    {
        self::$config = $configuration->values();
        self::$booted = true;
    }

    public static function load(): void
    {
        if (self::$booted) {
            return;
        }

        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config.php';

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
        return self::snapshot()->get($key, $default);
    }

    public static function snapshot(): \TinyCat\Core\Runtime\Configuration
    {
        self::load();
        return new \TinyCat\Core\Runtime\Configuration(self::$config);
    }
}
