<?php
declare(strict_types=1);
namespace TinyCat\Moderation;

use TinyCat\Site\Layout;
use TinyCat\Support\AdminViews;
use TinyCat\Support\Localization;
use TinyCat\Users\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function reports(): void
    {
        Access::require_admin();
        $data = Reports::tc_admin_moderation_reports_view_data();
        $title = Localization::t('moderation.reports_title');
        $content = AdminViews::listCard($title, 'flag', 'moderation-reports', 'moderation-filter-modal', new \TinyCat\Support\Markup(Views::reports($data)), primary: true);
        Layout::render(['title' => $title, 'current' => '/admin/moderation/reports'], $content);
    }
}
