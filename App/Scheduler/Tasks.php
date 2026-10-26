<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

final class Tasks
{
    /** @return array<string, array<mixed>> */
    public static function definitions(): array
    {
        return \TinyCat\Bots\Importer::scheduledTasks() + ['cleanup' => [
            'runner' => static function (array $context): array {
                $batch = Cleanup::cleanup_batch_size($context['options']['cleanup_batch'] ?? 500);
                $result = Cleanup::cron_cleanup_run($batch, ($context['mode'] ?? '') === 'admin');
                $failed = array_any((array) ($result['results'] ?? []), static fn (array $task): bool => isset($task['error']));
                return ['ok' => !$failed, 'status' => $failed ? 'failed' : (empty($result['due']) ? 'not_due' : (!empty($result['has_more']) ? 'has_more' : 'completed')), 'batch_size' => $batch, ...$result];
            },
            'options' => ['cleanup_batch' => 500],
            'admin' => ['icon' => 'database', 'title' => 'cron.tasks.cleanup', 'help' => 'cron.tasks.cleanup_help', 'schedule' => 'cron.tasks.cleanup_schedule'],
        ]];
    }
}
