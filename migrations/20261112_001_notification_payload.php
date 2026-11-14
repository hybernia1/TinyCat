<?php
declare(strict_types=1);

// Immutable migration snapshot; current schema helpers may evolve independently.
if (!defined('TINYCAT')) { return null; }
return static function (\PDO $pdo): void {
    $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $query = $pdo->query($sqlite ? "PRAGMA table_info(notifications)" : "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'");
    if ($query === false) { throw new \RuntimeException('Cannot inspect inbox schema.'); }
    $columns = array_column($query->fetchAll(\PDO::FETCH_ASSOC), 'name');
    if ($columns === []) { return; }
    if (!in_array('payload_json', $columns, true)) { $pdo->exec('ALTER TABLE notifications ADD COLUMN payload_json ' . ($sqlite ? 'TEXT' : 'LONGTEXT') . ' NULL'); }
    // Legacy reference columns remain authoritative for old social types during transition.
    // Existing FK behavior is preserved; the general inbox never queries those subjects.
};
