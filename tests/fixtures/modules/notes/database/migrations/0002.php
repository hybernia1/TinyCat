<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM pilot_notes')->fetchAll(PDO::FETCH_COLUMN);
    // MySQL DDL can commit before a worker stops; retry must not add the column twice.
    if (!in_array('refresh_count', $columns, true)) { $pdo->exec('ALTER TABLE pilot_notes ADD COLUMN refresh_count INTEGER NOT NULL DEFAULT 0'); }
};
