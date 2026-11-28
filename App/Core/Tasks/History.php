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
        Connection::exec("UPDATE scheduler_runs SET status = 'interrupted', finished_at = NULL WHERE task = ? AND status = 'running'", [$task]);
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
        $stored = Connection::value('SELECT summary_json FROM scheduler_runs WHERE id = ?', [$id]);
        $progress = json_decode((string) ($stored ?: '{}'), true, flags: JSON_THROW_ON_ERROR);
        $summary = array_replace($progress, $result['summary']);
        if (isset($progress['checkpoint'])) { $summary['checkpoint'] = $progress['checkpoint']; }
        // Common counters always describe the returned result, never owner-supplied aliases.
        foreach (['count', 'changed', 'has_more'] as $key) { $summary[$key] = $result[$key]; }
        Connection::update('scheduler_runs', [
            'status' => (string) $result['status'],
            'finished_at' => Dates::dateDb(), 'duration_ms' => max(0, $duration),
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
            'error' => $error !== '' ? \TinyCat\Core\Text\Text::plain_text_limit($error, 1000) : null,
        ], ['id' => $id]);
    }
    /** Owner-defined durable progress, written under the task lock. No interpretation in Tasks.
     * @param array<string, mixed> $summary */
    public static function checkpoint(int $id, array $summary): void
    {
        if ($id < 1 || !isset($summary['checkpoint']) || !is_array($summary['checkpoint'])) { throw new \LogicException('Task progress requires a scheduler run and owner checkpoint.'); }
        $json = json_encode($summary, JSON_THROW_ON_ERROR);
        $changed = Connection::update('scheduler_runs', ['summary_json' => $json], ['id' => $id, 'status' => 'running']);
        // MySQL reports zero for an identical write as well as a missing/non-running row.
        if ($changed === 0 && Connection::value("SELECT summary_json FROM scheduler_runs WHERE id = ? AND status = 'running'", [$id]) !== $json) {
            throw new \LogicException('Task checkpoint requires an existing running record.');
        }
    }

    /** Last durable owner state, independent of later failures or skipped invocations.
     * @return array<string, mixed>|null */
    public static function lastCheckpoint(string $task): ?array
    {
        $json = Connection::value("SELECT summary_json FROM scheduler_runs WHERE task = ? AND JSON_EXTRACT(summary_json, '$.checkpoint') IS NOT NULL ORDER BY id DESC LIMIT 1", [$task]);
        if ($json === null || $json === false) { return null; }
        $summary = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($summary) || !isset($summary['checkpoint']) || !is_array($summary['checkpoint'])) { throw new \UnexpectedValueException('Invalid task checkpoint.'); }
        return $summary;
    }

    /** Bounded orphan recovery, used by maintenance, never by a live callback's readers. */
    public static function recover(int $limit): void
    {
        foreach (Connection::all("SELECT DISTINCT task FROM scheduler_runs WHERE status = 'running' ORDER BY task LIMIT " . max(1, min(5000, $limit))) as $row) {
            $task = (string) $row['task'];
            $lock = Runner::lockName($task);
            // GET_LOCK is reentrant on our connection: never acquire an already-owned lock.
            if (Connection::value('SELECT IS_USED_LOCK(?)', [$lock]) !== null) { continue; }
            if ((int) Connection::value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) { continue; }
            try { Connection::exec("UPDATE scheduler_runs SET status = 'interrupted', finished_at = NULL WHERE task = ? AND status = 'running'", [$task]); }
            finally { Connection::value('SELECT RELEASE_LOCK(?)', [$lock]); }
        }
    }

    /** Retain only the newest checkpoint and successful states per task beyond 30 days.
     * Inactive owners have the same protection; running rows are never retention candidates. */
    public static function retention(): \TinyCat\Core\Modules\MaintenanceTask
    {
        $task = \TinyCat\Database\Retention::task('old_scheduler_runs', 'scheduler_runs', "started_at < ? AND status <> 'running' AND id NOT IN (
            SELECT id FROM (
                SELECT MAX(id) AS id FROM scheduler_runs WHERE status IN ('completed', 'has_more') GROUP BY task, status
                UNION SELECT MAX(id) AS id FROM scheduler_runs WHERE JSON_EXTRACT(summary_json, '$.checkpoint') IS NOT NULL GROUP BY task
            ) retained_runs
        )", static fn (): array => [Dates::dateDb('-30 days')], 120);
        return new \TinyCat\Core\Modules\MaintenanceTask($task->id, static function (int $batch) use ($task): array {
            self::recover($batch);
            return ($task->run)($batch);
        }, $task->order, $task->delete, $task->pending);
    }

    /** @return array<mixed>|null */
    public static function latest(string $task): ?array
    {
        return Connection::one('SELECT * FROM scheduler_runs WHERE task = ? ORDER BY id DESC LIMIT 1', [$task]);
    }

    /** Recent canonical run resources, newest first, including inactive owners.
     * @param list<string> $excludedStatuses
     * @return list<array<string, mixed>> */
    public static function recent(string $task, int $limit = 20, array $excludedStatuses = []): array
    {
        if (array_diff($excludedStatuses, Resource::STATUSES) !== []) { throw new \InvalidArgumentException('Unknown run status.'); }
        $limit = max(1, min(100, $limit));
        $excluded = $excludedStatuses === [] ? '' : ' AND status NOT IN (' . implode(', ', array_fill(0, count($excludedStatuses), '?')) . ')';
        return array_values(array_map(Resource::run(...), Connection::all('SELECT * FROM scheduler_runs WHERE task = ?' . $excluded . ' ORDER BY id DESC LIMIT ' . $limit, [$task, ...$excludedStatuses])));
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
