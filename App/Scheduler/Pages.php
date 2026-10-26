<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Http\Request;
use TinyCat\Site\Layout;
use TinyCat\Site\Presentation;
use TinyCat\Support\Localization;
use TinyCat\Support\Paths;
use TinyCat\Support\View;
use TinyCat\Users\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function listing(): void
    {
        Access::require_admin();
        $title = Localization::t('cron.list_title');
        $data = Administration::payload();
        $content = \TinyCat\Support\AdminViews::listCard($title, 'clock', 'scheduler-list', 'scheduler-filter-modal', new \TinyCat\Support\Markup(\TinyCat\Support\Components::render('scheduler/list', $data + ['state' => $data])));
        Layout::render(['title' => $title, 'current' => '/admin/cron/list'], $content);
    }

    public static function administration(): void
    {
        Access::require_admin();

        if (Request::current()->method() !== 'GET') {
            header('Allow: GET');
            \TinyCat\Site\Errors::respond(405);
            return;
        }

        $cronToken = Tokens::cron_token(true);
        $cronUrl = Presentation::absolute_url('/scheduled-tasks.php');
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
            $view['query_url'] = $taskUrl . '&bearer=' . rawurlencode($cronToken);
            $view['http_command'] = 'curl -fsS -X POST -H "Authorization: Bearer ' . $cronToken . '" "' . $taskUrl . '"';
            $view['cli_command'] = 'php "' . $runnerPath . '" --task=' . $task . ' ' . (string) $view['cli_arguments'];
        }
        unset($view);

        Layout::render([
            'title' => Localization::t('cron.title'),
            'current' => '/admin/cron',
        ], View::render('administration', compact('cronToken', 'cronUrl', 'taskViews'), __DIR__ . '/templates'));
    }
}
