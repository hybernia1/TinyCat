<?php
declare(strict_types=1);
namespace TinyCat\Settings;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return ['settings'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        foreach ([[['GET', 'POST'], '/admin/settings', 'admin/settings.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
    }
}
