<?php
declare(strict_types=1);

namespace TinyCat\Database;

final class Queries
{
    public static function db_select(string $sql): \TinyCat\Database\Query
    {
        return new \TinyCat\Database\Query($sql);
    }

    /**
     * @param array<mixed> $pagination
     */
    public static function pagination_sql(array $pagination): string
    {
        $perPage = max(1, min(200, (int) ($pagination['per_page'] ?? 15)));
        $offset = max(0, (int) ($pagination['offset'] ?? 0));

        return ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    }
}
