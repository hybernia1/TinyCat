<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

use TinyCat\Support\AdminViews;

final class Resource
{
    public const STATUSES = ['running', 'completed', 'failed', 'not_due', 'has_more', 'interrupted'];

    /** @param array<mixed> $records
     * @param array<mixed> $definitions
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function listing(array $records, array $definitions, array $filters): array
    {
        $listDefinition = \TinyCat\Core\Ui\Admin\Definitions::get('core.scheduler-runs');
        $pagination = $records['pagination'];
        $page = (int) $pagination['page'];
        unset($filters['per_page']);
        $params = $filters + ['per_page' => (int) $pagination['per_page']];
        $items = [];
        foreach ($definitions as $name => $definition) {
            $latest = $records['latest'][$name] ?? null;
            $items[] = ['name' => $name, 'options' => $definition['options'], 'latest' => $latest !== null ? self::run($latest) : null,
                'run_url' => AdminViews::admin_list_url($listDefinition['endpoint'] . '/' . $name . '/runs', $params + ['page' => $page])];
        }
        $navigation = AdminViews::admin_pagination_view_data($pagination, $listDefinition['endpoint'], $listDefinition['target'], $params, 'page', 2, $listDefinition['path']);
        unset($navigation['target']);
        return [
            'sorting' => \TinyCat\Support\AdminSorting::headings(\TinyCat\Core\Tasks\Repository::sorting(), $params),
            'items' => $items, 'runs' => array_map(self::run(...), $records['runs']),
            'filters' => $filters, 'pagination' => $pagination,
            'refresh_url' => AdminViews::admin_list_url($listDefinition['endpoint'], $params + ['page' => $page]),
            'task_choices' => array_keys($definitions), 'status_choices' => self::STATUSES,
            'navigation' => $navigation,
        ];
    }

    /** @param array<mixed> $record
     * @return array<string, mixed>
     */
    public static function detail(array $record): array
    {
        return self::run($record) + ['actor' => !empty($record['actor_id']) ? ['id' => (int) $record['actor_id'], 'username' => (string) ($record['actor_username'] ?? '')] : null];
    }

    /** @param array<mixed> $run
     * @return array<string, mixed>
     */
    public static function run(array $run): array
    {
        $task = (string) $run['task'];
        $options = json_decode((string) ($run['options_json'] ?? '{}'), true) ?: [];
        $summary = json_decode((string) ($run['summary_json'] ?? '{}'), true) ?: [];
        return [
            'id' => (int) $run['id'], 'task' => $task,
            'detail_url' => '/api/scheduler/runs/' . (int) $run['id'],
            'started_at' => \TinyCat\Support\Dates::dateIso((string) $run['started_at']), 'finished_at' => !empty($run['finished_at']) ? \TinyCat\Support\Dates::dateIso((string) $run['finished_at']) : null,
            'duration_ms' => $run['duration_ms'] !== null ? (int) $run['duration_ms'] : null,
            'status' => (string) $run['status'],
            'mode' => (string) $run['mode'],
            'options' => $options,
            'summary' => $summary, 'error' => (string) ($run['error'] ?? ''),
        ];
    }
}
