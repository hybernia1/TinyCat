<?php
declare(strict_types=1);
namespace TinyCat\Core\Installation;

final class ServiceProvider implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesInstallation
{
    public static function install(string $locale): void
    {
        Installer::create_tables(); Installer::default_settings(['locale' => $locale]);
    }
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/install', static function (): void { Pages::page(); });
    }
}
