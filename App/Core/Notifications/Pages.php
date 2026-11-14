<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;
use TinyCat\Core\Notifications\Inbox;

use TinyCat\Http\Request;
use TinyCat\Core\Ui\Page as Layout;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Core\Identity\Access;

/** HTTP page actions and prepared presentation composition. */
final class Pages
{
    public static function page(): void
    {
        $user = Access::requireAuth('/login');
        $userId = (int) ($user['id'] ?? 0);

        $batch = Inbox::page($userId);
        $unread = Inbox::unreadCount($userId);
        $viewItems = (array) $batch['items'];

        Layout::render([
            'title' => Localization::t('notifications.title'),
            'current' => '/notifications',
            'meta' => [
                'description' => Localization::t('notifications.meta'),
                'url' => '/notifications',
                'robots' => 'noindex,nofollow',
            ],
        ], View::render('page', compact('batch', 'unread', 'viewItems'), __DIR__ . '/templates'));
    }
}
