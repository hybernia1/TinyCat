<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Database\Connection;
use TinyCat\Support\Dates;

final class History
{
    /** @param array<string, mixed> $context */
    public static function start(string $task, array $context): int
    {
        // The caller owns the task lock; any older unfinished run lost its owner.
        Connection::exec("UPDATE scheduler_runs SET status = 'interrupted', finished_at = ? WHERE task = ? AND status = 'running'", [Dates::dateDb(), $task]);
        $mode = (string) ($context['mode'] ?? 'cli');
        return (int) Connection::insert('scheduler_runs', [
            'task' => $task, 'mode' => in_array($mode, ['cli', 'http', 'admin'], true) ? $mode : 'cli',
            'actor_id' => (int) ($context['actor_id'] ?? 0) ?: null,
            'status' => 'running', 'started_at' => Dates::dateDb(),
            'options_json' => json_encode((array) ($context['options'] ?? []), JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string, mixed> $result */
    public static function finish(int $id, array $result, int $duration): void
    {
        $error = (string) $result['error'];
        Connection::update('scheduler_runs', [
            'status' => (string) $result['status'],
            'finished_at' => Dates::dateDb(), 'duration_ms' => max(0, $duration),
            'summary_json' => json_encode($result['summary'], JSON_THROW_ON_ERROR),
            'error' => $error !== '' ? \TinyCat\Support\Text::plain_text_limit($error, 1000) : null,
        ], ['id' => $id]);
    }
}
