<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Database\Connection;

final class Repository
{
    /** @param array<string, mixed> $filters
     * @param list<string> $taskAliases
     * @return array{runs: array<mixed>, pagination: array<string, int>}
     */
    public static function listing(array $filters, int $page, int $perPage, array $taskAliases = []): array
    {
        $where = []; $params = [];
        $query = (string) ($filters['q'] ?? '');
        if ($query !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $params = [$pattern, $pattern, $pattern, ...$taskAliases];
            $extra = $taskAliases !== [] ? ' OR task IN (' . implode(', ', array_fill(0, count($taskAliases), '?')) . ')' : '';
            $where[] = "(task LIKE ? ESCAPE '!' OR error LIKE ? ESCAPE '!' OR mode LIKE ? ESCAPE '!'" . $extra . ')';
        }
        foreach (['task', 'status'] as $key) {
            if (($filters[$key] ?? '') !== '') { $where[] = $key . ' = ?'; $params[] = $filters[$key]; }
        }
        $sql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Connection::value('SELECT COUNT(*) FROM scheduler_runs' . $sql, $params);
        $perPage = max(1, min(200, $perPage));
        $last = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($last, $page));
        return [
            'runs' => Connection::all('SELECT * FROM scheduler_runs' . $sql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params),
            'pagination' => ['page' => $page, 'last_page' => $last, 'total' => $total, 'per_page' => $perPage, 'from' => $total > 0 ? ($page - 1) * $perPage + 1 : 0, 'to' => min($total, $page * $perPage)],
        ];
    }

    /** @return array<mixed>|null */
    public static function latest(string $task): ?array
    {
        return Connection::one('SELECT * FROM scheduler_runs WHERE task = ? ORDER BY id DESC LIMIT 1', [$task]);
    }

    /** @return array<mixed> */
    public static function sources(int $runId): array
    {
        return Connection::all('SELECT br.*, bs.name AS source_name FROM bot_source_runs br INNER JOIN bot_sources bs ON bs.id = br.source_id WHERE br.scheduler_run_id = ? ORDER BY br.id ASC', [$runId]);
    }

    /** @return array<mixed>|null */
    public static function find(int $id): ?array
    {
        return Connection::one('SELECT sr.*, u.username AS actor_username FROM scheduler_runs sr LEFT JOIN users u ON u.id = sr.actor_id WHERE sr.id = ?', [$id]);
    }
}
