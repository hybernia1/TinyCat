<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Support\AdminViews;
use TinyCat\Http\Web;

/** Validated HTTP list state, shared by the initial page and API requests. */
final class Administration
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        $definitions = Tasks::definitions();
        $task = (string) Web::get('task', '');
        $status = (string) Web::get('status', '');
        $filters = [
            'q' => AdminViews::admin_filter_text((string) Web::get('q', '')),
            'task' => isset($definitions[$task]) ? $task : '',
            'status' => in_array($status, Resource::STATUSES, true) ? $status : '',
            'per_page' => AdminViews::admin_per_page(),
        ] + \TinyCat\Support\AdminSorting::state('runs');
        $aliases = [];
        if ($filters['q'] !== '') {
            foreach ($definitions as $name => $definition) {
                $title = \TinyCat\Support\Localization::t($definition['admin']['title']);
                $matches = function_exists('mb_stripos') ? mb_stripos($title, $filters['q'], 0, 'UTF-8') !== false : stripos($title, $filters['q']) !== false;
                if ($matches) { $aliases[] = $name; }
            }
        }
        $records = Repository::listing($filters, AdminViews::admin_page(), $filters['per_page'], $aliases);
        $records['latest'] = [];
        foreach (array_keys($definitions) as $name) { $records['latest'][$name] = Repository::latest($name); }
        return Resource::listing($records, $definitions, $filters);
    }

    /** @return array<string, mixed>|null */
    public static function runDetail(int $id): ?array
    {
        $record = Repository::find($id);
        return $record !== null ? Resource::detail($record) + ['sources' => \TinyCat\Bots\Resource::runs(Repository::sources($id))] : null;
    }
}
