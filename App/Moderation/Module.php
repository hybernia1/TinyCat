<?php
declare(strict_types=1);
namespace TinyCat\Moderation;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/moderation', static function (): void {
            \TinyCat\Http\Web::redirect('/admin/moderation/reports');
        });
        foreach ([['GET', '/admin/moderation/reports', 'admin/moderation/reports.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
        foreach ([['GET', '/admin/moderation/blocking', 'admin/moderation/blocking.php']] as [$methods, $pagePath, $file]) {
            \TinyCat\Http\Router::route($methods, $pagePath, static function () use ($file): void {
                require \TinyCat\Support\Paths::publicPath($file);
            });
        }
    }
}
