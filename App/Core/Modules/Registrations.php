<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use TinyCat\Core\Modules\Registries;
use TinyCat\Support\Paths;

final class Registrations
{
    private static ?Registries $current = null;
    private static int $revision = -1;
    private static bool $bound = false;
    private static ?Loader $modules = null;

    public static function bind(?Loader $modules): void
    {
        self::$bound = true;
        self::$modules = $modules;
    }

    public static function configure(Registries $registries): void
    {
        self::$current = $registries;
        self::$revision = Resources::revision();
    }

    public static function current(): Registries
    {
        if (self::$current === null || self::$revision !== Resources::revision()) {
            $modules = self::$bound ? self::$modules : Loader::load(Paths::basePath());
            self::configure($modules?->registries(Resources::catalog()) ?? new Registries());
        }
        return self::$current ?? throw new \LogicException('Registries were not configured.');
    }
}
