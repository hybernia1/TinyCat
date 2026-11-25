<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;
use TinyCat\Core\Notifications\Inbox;

final class ServiceProvider implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Modules\ProvidesSchemas
{


    public static function schemas(): array { return ['tinycat.notifications' => Schema::schema()]; }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(notificationInbox: true, maintenance: [
            \TinyCat\Database\Retention::task('old_read_notifications', 'notifications', 'read_at IS NOT NULL AND read_at < ?', static fn (): array => [\TinyCat\Support\Dates::dateDb('-30 days')], 110),
        ]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/notifications/open', static function (): void {
            $user = \TinyCat\Core\Identity\Access::requireAuth('/login');
            $item = \TinyCat\Database\Connection::find('notifications', ['id' => (int) \TinyCat\Http\Web::get('id', 0), 'user_id' => (int) $user['id']]);
            \TinyCat\Http\Web::redirect($item === null ? '/notifications' : Inbox::targetUrl($item));
        });

        \TinyCat\Http\Router::route('GET', '/notifications', static function (): void {
            Pages::page();
        });
    }
}
