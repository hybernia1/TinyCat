<?php
declare(strict_types=1);
namespace TinyCat\Updates;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        foreach ([[['GET', 'POST'], '/admin/updates', 'admin/updates.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
    }
}
