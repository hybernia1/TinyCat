<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;
interface ProvidesInstallation
{
    public static function install(string $locale): void;
}
