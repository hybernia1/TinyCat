<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Database\Connection;
use TinyCat\Database\Query;

final class Repository
{
    /** @param array<string, mixed> $filters
     * @param list<string> $taskAliases
     * @return array{runs: array<mixed>, pagination: array<string, int>}
     */
    public static function listing(array $filters, int $page, int $perPage, array $taskAliases = []): array
    {
        $selection = new Query('SELECT * FROM scheduler_runs');
        $text = (string) ($filters['q'] ?? '');
        if ($text !== '') {
            $pattern = '%' . Connection::escapeLike($text) . '%';
            $extra = $taskAliases !== [] ? ' OR task IN (' . implode(', ', array_fill(0, count($taskAliases), '?')) . ')' : '';
            $selection->where("task LIKE ? ESCAPE '!' OR error LIKE ? ESCAPE '!' OR mode LIKE ? ESCAPE '!'" . $extra, $pattern, $pattern, $pattern, $taskAliases);
        }
        foreach (['task', 'status'] as $key) {
            if (($filters[$key] ?? '') !== '') { $selection->where($key . ' = ?', $filters[$key]); }
        }
        $total = $selection->count();
        $perPage = max(1, min(200, $perPage));
        $last = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($last, $page));
        return [
            'runs' => $selection->order(\TinyCat\Support\AdminSorting::order('runs', $filters))->limit($perPage, ($page - 1) * $perPage)->all(),
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
