<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

/** SQL allowlists and navigation owned by these lists. */
final class Sorting
{
    /** @return array{string, string, string, array<string, string>, string, string} */
    public static function runs(): array
    {
        return \TinyCat\Core\Ui\Admin\Definitions::sorting('core.scheduler-runs', ['task' => 'task', 'started_at' => 'started_at', 'status' => 'status', 'duration_ms' => 'duration_ms'], 'id DESC', 'id');
    }
}
