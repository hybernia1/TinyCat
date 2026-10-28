<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

/** One execution contract for API, command line and manual administration. */
final class Execution
{
    /** @return array<string, mixed> */
    public static function health(string $task = 'all', string $mode = 'http'): array
    {
        self::contexts($task, [], $mode);
        return ['service' => 'tinycat_scheduled_tasks', 'mode' => $mode, 'selected_task' => $task,
            'available_tasks' => array_keys(Tasks::definitions()), 'checked_at' => date(DATE_ATOM)];
    }

    /** Validate every selected task before starting any work.
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>> */
    public static function contexts(string $task, array $options, string $mode, int $actorId = 0): array
    {
        $definitions = Tasks::definitions();
        if ($task !== 'all' && !isset($definitions[$task])) { throw new \InvalidArgumentException('Unknown scheduled task.', 400); }
        $selected = $task === 'all' ? array_keys($definitions) : [$task];
        $allowed = [];
        foreach ($selected as $name) { $allowed = [...$allowed, ...array_keys($definitions[$name]['options'])]; }
        if ($mode === 'admin' && $task === 'feeds') { $allowed[] = 'source_id'; }
        if (array_diff(array_keys($options), $allowed) !== []) { throw new \InvalidArgumentException('Unknown scheduled task option.', 400); }
        $contexts = [];
        foreach ($selected as $name) {
            $keys = array_keys($definitions[$name]['options']);
            if ($mode === 'admin' && $name === 'feeds') { $keys[] = 'source_id'; }
            $contexts[$name] = Tasks::context($name, ['mode' => $mode, 'actor_id' => $actorId, 'options' => array_intersect_key($options, array_flip($keys))]);
        }
        return $contexts;
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed> */
    public static function run(string $task, array $options = [], string $mode = 'http', int $actorId = 0, bool $debug = false): array
    {
        $contexts = self::contexts($task, $options, $mode, $actorId);
        $definitions = Tasks::definitions();
        $results = [];
        foreach ($contexts as $name => $context) { $results[$name] = Runner::run($name, $definitions[$name]['runner'], $context, $debug); }
        return ['ok' => !array_any($results, static fn (array $result): bool => empty($result['ok'])),
            'service' => 'tinycat_scheduled_tasks', 'mode' => $mode, 'selected_task' => $task,
            'checked_at' => date(DATE_ATOM), 'tasks' => $results];
    }
}
