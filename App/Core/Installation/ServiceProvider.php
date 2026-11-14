<?php
declare(strict_types=1);
namespace TinyCat\Core\Installation;

final class ServiceProvider implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Modules\ProvidesInstallation
{
    public static function initialize(): void { \TinyCat\Core\Runtime\Startup::$legacySchemas = \TinyCat\Core\Modules\Distribution::read(\TinyCat\Support\Paths::basePath())['legacy'] ?? true; }
    public static function install(string $locale): void
    {
        Installer::create_tables(); Installer::default_settings(['locale' => $locale]);
    }
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/install', Pages::page(...));
    }
}
