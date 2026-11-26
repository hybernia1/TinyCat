<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

use TinyCat\Core\Identity\Access;
use TinyCat\Core\Ui\Page as Layout;
use TinyCat\Core\Ui\Components;
use TinyCat\Support\Localization;
use TinyCat\Core\Ui\Markup;

/** One administrative page for execution, history and connection details. */
final class Pages
{
    public static function show(): void
    {
        Access::require_admin();
        $definition = \TinyCat\Core\Ui\Admin\Definitions::get('core.scheduler-runs');
        $data = Administration::payload();
        $content = Components::render('scheduler/page', [
            'target_id' => substr($definition['target'], 1),
            'content' => new Markup(Components::render($definition['component'], $data + ['state' => $data])),
        ]);
        Layout::render(['title' => Localization::t($definition['title']), 'current' => $definition['path'], 'actions' => Components::render('ui/modal-trigger', ['modal' => 'scheduler-configuration', 'url' => '/api/scheduler/configuration', 'label' => Localization::t('cron.configuration'), 'icon' => 'plug', 'class' => 'btn btn-primary btn-sm'])], $content);
    }

}
