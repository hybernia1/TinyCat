<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

/** SQL allowlists and navigation owned by these lists. */
final class Sorting
{
    public const LISTS = [
        'runs' => ['/api/scheduler/tasks', '/admin/cron/list', '#scheduler-list', ['task' => 'task', 'started_at' => 'started_at', 'status' => 'status', 'duration_ms' => 'duration_ms'], 'id DESC', 'id'],
    ];
}
