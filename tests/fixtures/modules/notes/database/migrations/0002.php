<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $columns = $pdo->query('SHOW COLUMNS FROM pilot_notes')->fetchAll(PDO::FETCH_COLUMN);
    } else { $columns = array_column($pdo->query('PRAGMA table_info(pilot_notes)')->fetchAll(PDO::FETCH_ASSOC), 'name'); }
    // MySQL DDL can commit before a worker stops; retry must not add the column twice.
    if (!in_array('refresh_count', $columns, true)) { $pdo->exec('ALTER TABLE pilot_notes ADD COLUMN refresh_count INTEGER NOT NULL DEFAULT 0'); }
};
