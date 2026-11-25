<?php
declare(strict_types=1);
namespace TinyCat\Core\Installation;

final class ServiceProvider implements \TinyCat\Core\Modules\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/install', static function (): void { Pages::page(); });
        \TinyCat\Http\Router::route('GET', '/install/recovery', static function (): void { Pages::recovery(); });
    }
}
