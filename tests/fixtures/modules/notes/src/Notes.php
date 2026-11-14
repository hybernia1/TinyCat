<?php
declare(strict_types=1);

namespace TinyCat\Modules\PilotNotes;

use TinyCat\Database\Connection;

final class Notes
{
    /** @return list<array{id: int, title: string, created_by: int, refresh_count: int}> */
    public static function all(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'title' => (string) $row['title'], 'created_by' => (int) $row['created_by'], 'refresh_count' => (int) $row['refresh_count'],
        ], Connection::all('SELECT id, title, created_by, refresh_count FROM pilot_notes ORDER BY id'));
    }

    public static function create(string $title, int $actor): int
    {
        if ($actor < 1 || trim($title) === '' || strlen($title) > 80) { throw new \InvalidArgumentException('Invalid note input.'); }
        Connection::exec('INSERT INTO pilot_notes (title, created_by) VALUES (?, ?)', [trim($title), $actor]);
        return (int) Connection::get()->lastInsertId();
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed> */
    public static function refresh(array $context): array
    {
        $ids = array_column(Connection::all('SELECT id FROM pilot_notes ORDER BY id LIMIT ' . (int) $context['options']['batch']), 'id');
        $changed = $ids === [] ? 0 : Connection::exec('UPDATE pilot_notes SET refresh_count = refresh_count + 1 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        return \TinyCat\Core\Tasks\Tasks::result('completed', count($ids), $changed);
    }
}
