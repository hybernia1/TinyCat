<?php
declare(strict_types=1);
namespace TinyCat\Settings;

use TinyCat\Http\Request;
use TinyCat\Http\Session;
use TinyCat\Http\Web;
use TinyCat\Security\Csrf;
use TinyCat\Site\Layout;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Users\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function administration(): void
    {
        Access::require_admin();

        if (Request::current()->isPost()) {
            Csrf::requireToken();

            try {
                Administration::tc_admin_settings_save();
                $message = Localization::t('settings.messages.saved');

                Session::flash('success', $message);
            } catch (\Throwable $exception) {
                Session::flash('error', $exception->getMessage());
            }

            Web::redirect('/admin/settings');
        }

        $settingsSections = Administration::tc_admin_settings_view_sections();

        Layout::render([
            'title' => Localization::t('settings.meta_title'),
            'current' => '/admin/settings',
        ], View::render('administration', compact('settingsSections'), __DIR__ . '/templates'));
    }
}
