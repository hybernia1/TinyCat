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
                $results = (array) $result['results'];
                $errors = array_values(array_filter(array_column($results, 'error')));
                return self::result(
                    $errors !== [] ? 'failed' : (empty($result['due']) ? 'not_due' : (!empty($result['has_more']) ? 'has_more' : 'completed')),
                    count($results),
                    array_sum(array_column($results, 'changed')),
                    !empty($result['has_more']),
                    ['last_run' => $result['last_run'], 'next_run' => $result['next_run'], 'tasks' => $results],
                    (string) ($errors[0] ?? ''),
                    $results
                );
            },
            'options' => ['cleanup_batch' => 500],
            'admin' => ['icon' => 'database', 'title' => 'cron.tasks.cleanup', 'help' => 'cron.tasks.cleanup_help', 'schedule' => 'cron.tasks.cleanup_schedule'],
        ], 'site-health' => [
            'runner' => \TinyCat\Health\Service::run(...),
            'options' => [],
            'admin' => ['icon' => 'shield', 'title' => 'health.title', 'help' => 'health.intro', 'schedule' => 'health.schedule'],
        ]];
    }

    /** @param array<string, mixed> $context
     * @return array{mode: string, actor_id: int, options: array<string, int>} */
    public static function context(string $name, array $context): array
    {
        $definition = self::definitions()[$name] ?? null;
        if ($definition === null) { throw new \InvalidArgumentException('Unknown scheduled task.'); }
        $mode = (string) ($context['mode'] ?? 'cli');
        $mode = in_array($mode, ['cli', 'http', 'admin'], true) ? $mode : 'cli';
        $input = (array) ($context['options'] ?? []);
        $allowed = array_keys($definition['options']);
        if ($name === 'feeds' && $mode === 'admin') { $allowed[] = 'source_id'; }
        if (array_diff(array_keys($input), $allowed) !== []) { throw new \InvalidArgumentException('Unknown scheduled task option.'); }
        foreach ($input as $key => $value) {
            if (!is_int($value) && !is_string($value)) { throw new \InvalidArgumentException('Task options must be integers.'); }
            $number = filter_var($value, FILTER_VALIDATE_INT);
            if ($number === false) { throw new \InvalidArgumentException('Task options must be integers.'); }
            $input[$key] = $number;
        }
        $options = array_replace($definition['options'], $input);
        if ($name === 'feeds') {
            $options['bot_limit'] = max(1, min(100, (int) $options['bot_limit']));
            if (isset($options['source_id'])) {
                $sourceId = filter_var($options['source_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($sourceId === false) { throw new \InvalidArgumentException('Invalid feed source ID.'); }
                $options = ['bot_limit' => 1, 'source_id' => $sourceId];
            }
        } elseif ($name === 'cleanup') {
            $options['cleanup_batch'] = Cleanup::cleanup_batch_size($options['cleanup_batch']);
        }
        return ['mode' => $mode, 'actor_id' => max(0, (int) ($context['actor_id'] ?? 0)), 'options' => $options];
    }

    /**
     * Count is processed work items; changed is created posts or removed records/files.
     * Details belong to the task and are stored unchanged alongside common counters.
     * @param array<string, mixed> $details
     * @param array<mixed> $results
     * @return array<string, mixed>
     */
    public static function result(string $status, int $count = 0, int $changed = 0, bool $hasMore = false, array $details = [], string $error = '', array $results = []): array
    {
        if (!in_array($status, ['completed', 'failed', 'not_due', 'has_more', 'already_running'], true)) {
            throw new \InvalidArgumentException('Invalid scheduled task result status.');
        }
        $count = max(0, $count);
        $changed = max(0, $changed);
        return [
            'ok' => $status !== 'failed', 'status' => $status,
            'count' => $count, 'changed' => $changed, 'has_more' => $hasMore,
            'skipped' => $status === 'already_running', 'error' => $error,
            'summary' => ['count' => $count, 'changed' => $changed, 'has_more' => $hasMore] + $details,
            'results' => $results,
        ];
    }
}
