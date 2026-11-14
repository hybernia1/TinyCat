<?php
declare(strict_types=1);

namespace TinyCat\Database;

use TinyCat\Core\Modules\MaintenanceTask;

/** SQL belongs to its module; this adapter only executes bounded retention. */
final class Retention
{
    /** @param callable(): array<mixed> $parameters */
    public static function task(string $id, string $table, string $where, callable $parameters, int $order): MaintenanceTask
    {
        $table = Schema::app_sql_identifier($table);
        $delete = static function (int $batch) use ($table, $where, $parameters): int {
            if ($batch < 1) { throw new \InvalidArgumentException('Retention batch must be positive.'); }
            return Connection::exec('DELETE FROM ' . $table . ' WHERE ' . $where . ' LIMIT ' . $batch, $parameters());
        };
        $pending = static function () use ($table, $where, $parameters): bool {
            $value = Connection::value('SELECT 1 FROM ' . $table . ' WHERE ' . $where . ' LIMIT 1', $parameters());
            return $value !== null && $value !== false;
        };
        return new MaintenanceTask($id, static function (int $batch) use ($delete, $pending): array {
            $changed = $delete($batch);
            return ['changed' => $changed, 'has_more' => $changed >= $batch && $pending()];
        }, $order, $delete, $pending);
    }
}
