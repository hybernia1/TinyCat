<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $query = $pdo->query($sqlite ? "PRAGMA index_list('notifications')" : 'SHOW INDEX FROM notifications');
    if ($query === false) { throw new \RuntimeException('Cannot inspect notification indexes.'); }
    $existing = array_column($query->fetchAll(\PDO::FETCH_ASSOC), $sqlite ? 'name' : 'Key_name');
    foreach (['notifications_user_created_id_index' => 'user_id, created_at DESC, id DESC', 'notifications_user_id_index' => 'user_id, id'] as $name => $columns) {
        if (!in_array($name, $existing, true)) { $pdo->exec('CREATE INDEX ' . $name . ' ON notifications (' . $columns . ')'); }
    }
};
