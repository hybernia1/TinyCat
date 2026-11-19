<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Support\{Components, Format, Localization};

/** Framework overview plus lazy contributions from active owners. */
final class Dashboard
{
    public static function respond(): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        $stats = [];
        try { $roles = \TinyCat\Core\Identity\Administration::roleCounts(); }
        catch (\Throwable) { $roles = array_fill_keys(\TinyCat\Core\Identity\Roles::rolesWith('managed_by_core_admin'), null); }
        foreach ($roles as $role => $value) {
            $key = 'users.roles.' . $role;
            $label = Localization::t($key);
            $stats[] = ['icon' => 'users', 'label' => $label === $key ? $role : $label, 'value' => $value === null ? '—' : Format::number($value)];
        }
        foreach (['notifications' => 'notifications'] as $label => $table) {
            try { $value = \TinyCat\Database\Connection::count($table); } catch (\Throwable) { $value = null; }
            $stats[] = ['icon' => 'database', 'label' => Localization::t('admin.stats.' . $label), 'value' => $value === null ? '—' : Format::number($value)];
        }
        $content = Components::render('ui/dashboard-overview', ['stats' => $stats]);
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->widgets as $widget) { $content .= ($widget->render)(); }
        \TinyCat\Core\Ui\Page::render(['title' => Localization::t('common.dashboard'), 'current' => '/admin'], Components::render('ui/dashboard', ['content' => new \TinyCat\Support\Markup($content)]));
    }
}
