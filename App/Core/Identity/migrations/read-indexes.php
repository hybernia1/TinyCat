<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $query = $pdo->query($sqlite ? "PRAGMA index_list('users')" : 'SHOW INDEX FROM users');
    if ($query === false) { throw new \RuntimeException('Cannot inspect account indexes.'); }
    $existing = array_column($query->fetchAll(\PDO::FETCH_ASSOC), $sqlite ? 'name' : 'Key_name');
    foreach (['users_updated_asc_index' => 'updated_at ASC, id DESC', 'users_updated_desc_index' => 'updated_at DESC, id DESC', 'users_status_asc_index' => 'status ASC, id DESC', 'users_status_desc_index' => 'status DESC, id DESC'] as $name => $columns) {
        if (!in_array($name, $existing, true)) { $pdo->exec('CREATE INDEX ' . $name . ' ON users (' . $columns . ')'); }
    }
};
