<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;
use TinyCat\Core\Notifications\Inbox;

use TinyCat\Http\Request;
use TinyCat\Core\Ui\Page as Layout;
use TinyCat\Support\Localization;
use TinyCat\Support\Components;
use TinyCat\Core\Identity\Access;

/** HTTP page actions and prepared presentation composition. */
final class Pages
{
    public static function page(): void
    {
        $user = Access::requireAuth('/login');
        $userId = (int) ($user['id'] ?? 0);

        $batch = Inbox::page($userId);
        $public = \TinyCat\Core\Ui\PublicWeb::enabled();
        $state = $public ? Inbox::state($userId) : ['unread' => Inbox::unreadCount($userId)];
        $items = array_map(Resource::item(...), (array) $batch['items']);

        Layout::render([
            'title' => Localization::t('notifications.title'),
            'current' => '/notifications',
            'notificationState' => $state,
            'notificationItems' => array_slice($batch['items'], 0, Inbox::PREVIEW_LIMIT),
            'surface' => $public ? 'public' : 'access',
            'asset_path' => $public ? '/notifications' : '/access/notifications',
            'meta' => [
                'description' => Localization::t('notifications.meta'),
                'url' => '/notifications',
                'robots' => 'noindex,nofollow',
            ],
        ], Components::render('notifications/page', ['items' => $items, 'has_unread' => $state['unread'] > 0, 'next_url' => (string) $batch['next_url']]));
    }
}
