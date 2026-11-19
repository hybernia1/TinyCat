<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

use TinyCat\Core\Identity\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function reports(): void
    {
        Access::require_admin();
        \TinyCat\Core\Ui\Admin\Definitions::page('social.moderation-reports', Reports::tc_admin_moderation_reports_payload());
    }
}
