<?php
declare(strict_types=1);
namespace TinyCat\Moderation;

use TinyCat\Site\Layout;
use TinyCat\Support\AdminViews;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Users\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function blocking(): void
    {
        Access::require_admin();

        $blockedUrls = Blocking::tc_admin_moderation_blocked_urls_value();

        Layout::render([
            'title' => Localization::t('moderation.url_blocker_title'),
            'current' => '/admin/moderation/blocking',
        ], View::render('blocking', compact('blockedUrls'), __DIR__ . '/templates'));
    }

    public static function reports(): void
    {
        Access::require_admin();
        $data = Reports::tc_admin_moderation_reports_view_data();
        $title = Localization::t('moderation.reports_title');
        $content = AdminViews::listCard($title, 'flag', 'moderation-reports', '', new \TinyCat\Support\Markup(Views::reports($data)));
        Layout::render(['title' => $title, 'current' => '/admin/moderation/reports'], $content);
    }
}
