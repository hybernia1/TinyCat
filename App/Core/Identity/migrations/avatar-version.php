<?php
declare(strict_types=1);

// Add an independent immutable upload identity; existing rows keep their legacy path.
return static function (\PDO $pdo): void {
    $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $query = $pdo->query($sqlite ? 'PRAGMA table_info(users)' : 'SHOW COLUMNS FROM users');
    if ($query === false) { throw new \RuntimeException('Cannot inspect avatar schema.'); }
    $columns = $query->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($columns as $column) { if (($column[$sqlite ? 'name' : 'Field'] ?? '') === 'avatar_version') { return; } }
    $pdo->exec('ALTER TABLE users ADD COLUMN avatar_version VARCHAR(32) NULL');
};
