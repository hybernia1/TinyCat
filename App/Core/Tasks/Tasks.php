<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

class Tasks
{
    /** @return array<string, array<mixed>> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->tasks as $task) { $definitions[$task->id] = $task->definition(); }
        return $definitions;
    }

    /** @param array<string, mixed> $context
     * @return array{mode: string, actor_id: int, options: array<string, int>} */
    public static function context(string $name, array $context): array
    {
        $definition = \TinyCat\Core\Modules\Registrations::current()->task($name);
        $mode = (string) ($context['mode'] ?? 'cli');
        $mode = in_array($mode, ['cli', 'http', 'admin'], true) ? $mode : 'cli';
        $input = (array) ($context['options'] ?? []);
        $allowed = $definition->optionKeys($mode);
        if (array_diff(array_keys($input), $allowed) !== []) { throw new \InvalidArgumentException('Unknown scheduled task option.'); }
        foreach ($input as $key => $value) {
            if (!is_int($value) && !is_string($value)) { throw new \InvalidArgumentException('Task options must be integers.'); }
            $number = filter_var($value, FILTER_VALIDATE_INT);
            if ($number === false) { throw new \InvalidArgumentException('Task options must be integers.'); }
            $input[$key] = $number;
        }
        $options = array_replace($definition->options, $input);
        if ($definition->normalize !== null) { $options = ($definition->normalize)($options); }
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
