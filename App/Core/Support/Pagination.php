<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class Pagination
{

    /**
     * @return array<mixed>
     */
    public static function paginationMeta(int $total, ?int $page = null, int $perPage = 15): array
    {
        $page ??= max(1, (int) ($_GET['page'] ?? 1));
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $total = max(0, $total);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => $total === 0 ? 0 : min($total, $offset + $perPage),
            'has_prev' => $page > 1,
            'has_next' => $page < $lastPage,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ];
    }
}
