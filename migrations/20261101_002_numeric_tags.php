<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }

return static function (PDO $database): void {
    $owned = !$database->inTransaction();
    if ($owned) { $database->beginTransaction(); }
    try {
        $after = 0;
        do {
            $query = $database->prepare('SELECT id, name FROM terms WHERE id > ? ORDER BY id LIMIT 500');
            $query->execute([$after]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $ids = [];
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                if (preg_match('/^\p{N}+$/u', (string) $row['name']) === 1) { $ids[] = $after; }
            }
            if ($ids !== []) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                foreach (['content_tags' => 'term_id', 'terms' => 'id'] as $table => $column) {
                    $delete = $database->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $placeholders . ')');
                    $delete->execute($ids);
                }
            }
        } while (count($rows) === 500);
        if ($owned) { $database->commit(); }
    } catch (Throwable $exception) {
        if ($owned && $database->inTransaction()) { $database->rollBack(); }
        throw $exception;
    }
};
