<?php
declare(strict_types=1);
namespace TinyCat\Database;

/** Stable chronological selection for an owner's explicitly named columns. */
final class Keyset
{
    /** @return array{0: string, 1: list<string|int>} */
    public static function beforeDateId(string $at, int $id, string $dateColumn, string $idColumn): array
    {
        foreach ([$dateColumn, $idColumn] as $column) { if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $column) !== 1) { throw new \InvalidArgumentException('Invalid cursor column.'); } }
        $at = trim($at);
        return $id > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $at) === 1 ? ['(' . $dateColumn . ' < ? OR (' . $dateColumn . ' = ? AND ' . $idColumn . ' < ?))', [$at, $at, $id]] : ['', []];
    }
}
