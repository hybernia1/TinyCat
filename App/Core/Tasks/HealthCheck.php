<?php
declare(strict_types=1);

namespace TinyCat\Core\Tasks;

use TinyCat\Database\Connection;

final class HealthCheck
{
    /** @return array{status: string, metrics: array<string, string>, limits: array<string, int>} */
    public static function run(): array
    {
        $tasks = [];
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->tasks as $task) {
            $age = $task->healthAge !== null ? ($task->healthAge)() : null;
            if ($age !== null) {
                if ($age < 1) { throw new \InvalidArgumentException('Task health interval must be positive.'); }
                $tasks[$task->id] = $age;
            }
        }
        ksort($tasks);
        $metrics = []; $limits = []; $status = 'ok';
        foreach ($tasks as $task => $age) {
            $last = Connection::value("SELECT MAX(started_at) FROM scheduler_runs WHERE task = ? AND status IN ('completed', 'not_due', 'has_more')", [$task]);
            $latest = History::latest($task);
            $reason = in_array($latest['status'] ?? '', ['failed', 'interrupted'], true)
                ? (string) $latest['status']
                : (!is_string($last) ? 'missing' : ((strtotime($last) ?: 0) < time() - $age ? 'stale' : 'ok'));
            $metrics[$task] = $reason; $limits[$task] = $age;
            if ($reason !== 'ok') { $status = 'warning'; }
        }
        return ['status' => $status, 'metrics' => $metrics, 'limits' => $limits];
    }
}
