<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

use TinyCat\Database\Connection;
use TinyCat\Database\Query;

final class Repository
{
    /** @return array{string, string, string, array<string, string>, string, string} */
    public static function sorting(): array
    {
        return \TinyCat\Core\Ui\Admin\Definitions::sorting('core.scheduler-runs', ['task' => 'task', 'started_at' => 'started_at', 'status' => 'status', 'duration_ms' => 'duration_ms'], 'id DESC', 'id');
    }

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
            'runs' => $selection->order(\TinyCat\Support\AdminSorting::order(\TinyCat\Core\Tasks\Repository::sorting(), $filters))->limit($perPage, ($page - 1) * $perPage)->all(),
            'pagination' => ['page' => $page, 'last_page' => $last, 'total' => $total, 'per_page' => $perPage, 'from' => $total > 0 ? ($page - 1) * $perPage + 1 : 0, 'to' => min($total, $page * $perPage)],
        ];
    }

    /** @return array<mixed>|null */
    public static function latest(string $task): ?array
    {
        return \TinyCat\Core\Tasks\History::latest($task);
    }

    /** @return array<mixed>|null */
    public static function find(int $id): ?array
    {
        return Connection::one('SELECT sr.*, u.username AS actor_username FROM scheduler_runs sr LEFT JOIN users u ON u.id = sr.actor_id WHERE sr.id = ?', [$id]);
    }
}
