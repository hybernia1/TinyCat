<?php
declare(strict_types=1);

namespace TinyCat\Core\Tasks;

use Throwable;

final class Cleanup
{
    public static function scheduledTask(): \TinyCat\Core\Modules\ScheduledTask
    {
        return new \TinyCat\Core\Modules\ScheduledTask('cleanup', static function (array $context): array {
            $batch = self::cleanup_batch_size($context['options']['cleanup_batch'] ?? 500);
            $result = self::cron_cleanup_run($batch, ($context['mode'] ?? '') === 'admin');
            $results = (array) $result['results'];
            $errors = array_values(array_filter(array_column($results, 'error')));
            return Tasks::result(
                $errors !== [] ? 'failed' : (empty($result['due']) ? 'not_due' : (!empty($result['has_more']) ? 'has_more' : 'completed')),
                count($results), array_sum(array_column($results, 'changed')), !empty($result['has_more']),
                ['last_run' => $result['last_run'], 'next_run' => $result['next_run'], 'tasks' => $results],
                (string) ($errors[0] ?? ''), $results,
            );
        }, options: ['cleanup_batch' => 500], admin: ['icon' => 'database', 'title' => 'cron.tasks.cleanup', 'help' => 'cron.tasks.cleanup_help', 'schedule' => 'cron.tasks.cleanup_schedule', 'cadence' => 'cron.cadence.cleanup'],
            order: 20, normalize: static fn (array $options): array => ['cleanup_batch' => self::cleanup_batch_size($options['cleanup_batch'])], healthAge: static fn (): int => 4 * 3600, batchOption: 'cleanup_batch', optionLabels: ['cleanup_batch' => 'cron.options.cleanup_batch']);
    }

    public static function cleanup_batch_size(mixed $value): int
    {
        $size = (int) $value;
        $allowed = [500, 1000, 2500, 5000];

        return in_array($size, $allowed, true) ? $size : 1000;
    }

    /** Active tasks plus explicitly opted-in recovery for installed inactive data owners.
     * @return array<string, \TinyCat\Core\Modules\MaintenanceTask> */
    private static function maintenanceTasks(): array
    {
        $active = \TinyCat\Core\Modules\Registrations::current();
        $result = [];
        foreach ($active->entries->maintenance as $task) { $result[$task->id] = $task; }
        $owners = \TinyCat\Core\Files\References::owners();
        $state = \TinyCat\Core\Modules\Deployment::read(\TinyCat\Support\Paths::basePath());
        if ($state === null) { return $result; }
        $installed = $owners->registries();
        foreach ($installed->entries->maintenance as $task) {
            $owner = $installed->owner('maintenance', $task->id);
            if (!$task->whenInactive || isset($result[$task->id]) || in_array($owner, $state['active'], true)) { continue; }
            $catalog = $owners->catalog;
            $result[$task->id] = new \TinyCat\Core\Modules\MaintenanceTask($task->id, static fn (int $batch): array => \TinyCat\Core\Modules\ModuleAutoload::withCatalog($catalog, static fn (): array => ($task->run)($batch)), $task->order, whenInactive: true);
        }
        uasort($result, static fn (\TinyCat\Core\Modules\MaintenanceTask $a, \TinyCat\Core\Modules\MaintenanceTask $b): int => $a->order <=> $b->order);
        return $result;
    }

    /**
     * @return array<mixed>
     */
    public static function cron_cleanup_tasks(): array
    {
        return array_values(array_map(static fn (\TinyCat\Core\Modules\MaintenanceTask $task): string => $task->id, self::maintenanceTasks()));
    }

    /**
     * @param array<mixed> $tasks
     * @return array<mixed>
     */
    public static function cron_cleanup_run(int $batchSize = 500, bool $force = false, ?array $tasks = null): array
    {
        $batchSize = self::cleanup_batch_size($batchSize);
        $fullRun = $tasks === null;
        $tasks = $tasks === null
            ? self::cron_cleanup_tasks()
            : array_values(array_intersect(self::cron_cleanup_tasks(), $tasks));
        $now = time();
        $interval = 3600;
        $lastRun = max(0, (int) \TinyCat\Core\Settings\Store::setting('cron.cleanup_last_run', 0));

        if (!$force && $lastRun > $now - $interval) {
            return [
                'due' => false,
                'last_run' => $lastRun,
                'next_run' => $lastRun + $interval,
                'has_more' => false,
                'results' => [],
            ];
        }

        $results = [];
        if ($fullRun) {
            $nextTask = (string) \TinyCat\Core\Settings\Store::setting('cron.cleanup_next_task', \TinyCat\Core\Modules\Registrations::current()->initialMaintenance());
            $offset = array_search($nextTask, $tasks, true);
            if (is_int($offset)) { $tasks = [...array_slice($tasks, $offset), ...array_slice($tasks, 0, $offset)]; }
        }

        foreach ($tasks as $index => $task) {
            // Advance before work so even a killed PHP worker gives the following task its turn.
            if ($fullRun) { \TinyCat\Core\Settings\Store::setSetting('cron.cleanup_next_task', $tasks[($index + 1) % count($tasks)], 'string', 'cron'); }
            try {
                $results[$task] = self::cleanup_task_run($task, $batchSize);
            } catch (Throwable $exception) {
            \TinyCat\Core\Runtime\Failure::report($exception);
                $results[$task] = [
                    'task' => $task,
                    'changed' => 0,
                    'has_more' => false,
                    'batch_size' => $batchSize,
                    'error' => \TinyCat\Core\Runtime\Failure::message($exception, 'Operation failed.'),
                ];
            }
        }

        $hasMore = array_any($results, static fn (array $result): bool => !empty($result['has_more']));
        $hasErrors = array_any($results, static fn (array $result): bool => isset($result['error']));

        if (!$hasMore && !$hasErrors) {
            \TinyCat\Core\Settings\Store::setSetting('cron.cleanup_last_run', $now, 'int', 'cron');
        }

        return [
            'due' => true,
            'last_run' => $lastRun > 0 ? $lastRun : null,
            'next_run' => !$hasMore && !$hasErrors ? $now + $interval : null,
            'has_more' => $hasMore,
            'results' => $results,
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function cleanup_task_run(string $task, int $batchSize): array
    {
        $batchSize = self::cleanup_batch_size($batchSize);
        $startedAt = hrtime(true);
        $entries = self::maintenanceTasks();
        $definition = $entries[$task] ?? throw new \InvalidArgumentException('Unknown maintenance task: ' . $task);
        $result = ($definition->run)($batchSize);
        $error = (string) ($result['error'] ?? '');
        $changed = $result['changed'];
        $hasMore = $result['has_more'];

        return [
            'task' => $task,
            'changed' => $changed,
            'has_more' => $hasMore,
            'batch_size' => $batchSize,
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            'done' => !$hasMore && $error === '',
        ] + ($error !== '' ? ['error' => $error] : []);
    }

    public static function cleanup_has_rows(string $task): bool
    {
        $definition = \TinyCat\Core\Modules\Registrations::current();
        foreach ($definition->entries->maintenance as $entry) {
            if ($entry->id === $task) { return $entry->pending !== null && ($entry->pending)(); }
        }
        return false;
    }

    public static function cleanup_delete(string $task, int $batchSize): int
    {
        $batchSize = self::cleanup_batch_size($batchSize);

        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->maintenance as $entry) {
            if ($entry->id === $task) { return $entry->delete !== null ? ($entry->delete)($batchSize) : 0; }
        }
        return 0;
    }

    /**
     * @param array<mixed> $params
     */
    public static function cleanup_delete_limited(string $sql, array $params = []): int
    {
        return \TinyCat\Database\Connection::exec($sql, $params);
    }
}
