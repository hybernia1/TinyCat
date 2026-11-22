<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

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
    /** @return array<mixed>|null */
    public static function latest(string $task): ?array
    {
        return Connection::one('SELECT * FROM scheduler_runs WHERE task = ? ORDER BY id DESC LIMIT 1', [$task]);
    }

    /** @param list<string> $tasks
     * @return array<string, array<mixed>> */
    public static function latestFor(array $tasks, bool $workOnly = false): array
    {
        if ($tasks === []) { return []; }
        $marks = implode(', ', array_fill(0, count($tasks), '?'));
        $work = $workOnly ? " AND status NOT IN ('not_due', 'running')" : '';
        $rows = Connection::all('SELECT sr.* FROM scheduler_runs sr INNER JOIN (SELECT task, MAX(id) AS id FROM scheduler_runs WHERE task IN (' . $marks . ')' . $work . ' GROUP BY task) latest ON latest.id = sr.id', $tasks);
        $result = [];
        foreach ($rows as $row) { $result[(string) $row['task']] = $row; }
        return $result;
    }

    /** @param list<string> $tasks
     * @return array<string, string> */
    public static function lastSuccessfulFor(array $tasks): array
    {
        if ($tasks === []) { return []; }
        $marks = implode(', ', array_fill(0, count($tasks), '?'));
        $rows = Connection::all("SELECT task, MAX(started_at) AS started_at FROM scheduler_runs WHERE task IN (" . $marks . ") AND status IN ('completed', 'has_more') GROUP BY task", $tasks);
        $result = [];
        foreach ($rows as $row) { $result[(string) $row['task']] = (string) $row['started_at']; }
        return $result;
    }
}
