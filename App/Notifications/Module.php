<?php
declare(strict_types=1);
namespace TinyCat\Notifications;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return ['notifications'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/notifications/open', static function (): void {
            $user = \TinyCat\Users\Access::requireAuth('/login');
            $item = \TinyCat\Database\Connection::find('notifications', ['id' => (int) \TinyCat\Http\Web::get('id', 0), 'user_id' => (int) $user['id']]);
            \TinyCat\Http\Web::redirect($item === null ? '/notifications' : Inbox::targetUrl($item));
        });

        \TinyCat\Http\Router::route('GET', '/notifications', static function (): void {
            Pages::page();
        });
    }
}
