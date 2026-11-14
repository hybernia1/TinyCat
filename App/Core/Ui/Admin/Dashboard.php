<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Support\{Components, Localization};

/** Framework overview plus lazy contributions from active owners. */
final class Dashboard
{
    public static function respond(): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        $stats = [];
        foreach (['users' => 'users', 'links' => 'links', 'tags' => 'terms', 'notifications' => 'notifications'] as $label => $table) {
            try { $value = \TinyCat\Database\Connection::count($table); } catch (\Throwable) { $value = null; }
            $stats[] = ['icon' => $label === 'users' ? 'users' : 'database', 'label' => Localization::t('admin.stats.' . $label), 'value' => $value === null ? '—' : (string) $value];
        }
        $content = Components::render('ui/dashboard-overview', ['stats' => $stats]);
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->widgets as $widget) { $content .= ($widget->render)(); }
        \TinyCat\Core\Ui\Page::render(['title' => Localization::t('common.dashboard'), 'current' => '/admin'], '<div class="stack stack-gap-14" data-dashboard>' . $content . '</div>');
    }
}
