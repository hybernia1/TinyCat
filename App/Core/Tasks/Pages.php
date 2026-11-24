<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;
use TinyCat\Core\Tasks\Tasks;

use TinyCat\Http\Request;
use TinyCat\Core\Ui\Page as Layout;
use TinyCat\Support\LocalUrls;
use TinyCat\Support\Localization;
use TinyCat\Support\Paths;
use TinyCat\Core\Identity\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function listing(): void
    {
        Access::require_admin();
        $definition = \TinyCat\Core\Ui\Admin\Definitions::get('core.scheduler-runs');
        $title = Localization::t($definition['title']);
        $data = Administration::payload();
        $content = \TinyCat\Support\Components::render('scheduler/runs-page', [
            'target_id' => substr($definition['target'], 1),
            'content' => new \TinyCat\Support\Markup(\TinyCat\Support\Components::render($definition['component'], $data + ['state' => $data])),
        ]);
        Layout::render(['title' => $title, 'current' => $definition['path']], $content);
    }

    public static function administration(): void
    {
        Access::require_admin();

        if (Request::current()->method() !== 'GET') {
            header('Allow: GET');
            \TinyCat\Core\Runtime\HttpKernel::exception(new \DomainException('Method not allowed.', 405));
            return;
        }

        $cronToken = Tokens::cron_token();
        $cronUrl = LocalUrls::absolute('/api/scheduler/executions');
        $runnerPath = Paths::basePath('scheduled-tasks.php');
        $taskViews = [];

        foreach (Tasks::definitions() as $task => $definition) {
            $admin = $definition['admin'];

            $arguments = [];
            foreach ((array) $definition['options'] as $name => $default) {
                $arguments[] = '--' . str_replace('_', '-', (string) $name) . '=' . (int) $default;
            }

            $taskViews[$task] = [
                ...$admin,
                'cli_arguments' => implode(' ', $arguments),
            ];
        }

        foreach ($taskViews as $task => &$view) {
            $taskUrl = $cronUrl . '?task=' . rawurlencode($task);
            foreach (['title', 'help', 'schedule'] as $label) { $view[$label . '_label'] = Localization::t((string) $view[$label]); }
            $view['cadence_label'] = Localization::t((string) ($view['cadence'] ?? $view['schedule']));
            $view['query_url'] = LocalUrls::absolute('/api/scheduler/triggers/' . rawurlencode($task));
            $view['http_command'] = 'curl -fsS -X POST -H "Authorization: Bearer ' . $cronToken . '" "' . $taskUrl . '"';
            $view['cli_command'] = 'php "' . $runnerPath . '" --task=' . $task . ' ' . (string) $view['cli_arguments'];
        }
        unset($view);

        Layout::render([
            'title' => Localization::t('cron.title'),
            'current' => '/admin/cron',
            'actions' => \TinyCat\Support\Components::render('scheduler/actions'),
        ], \TinyCat\Support\AdminViews::listCard(Localization::t('cron.title'), 'clock', 'scheduler-settings', '', new \TinyCat\Support\Markup(\TinyCat\Support\Components::render('scheduler/administration', compact('cronToken', 'cronUrl', 'taskViews'))), primary: true));
    }
}
