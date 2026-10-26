<?php
declare(strict_types=1);
namespace TinyCat\Moderation;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return ['content_reports'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/moderation', static function (): void {
            \TinyCat\Http\Web::redirect('/admin/moderation/reports');
        });
        \TinyCat\Http\Router::route('GET', '/admin/moderation/reports', Pages::reports(...));
        \TinyCat\Http\Router::route('GET', '/admin/moderation/blocking', Pages::blocking(...));
    }
}
