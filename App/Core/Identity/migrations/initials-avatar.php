<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $query = $pdo->query('SHOW COLUMNS FROM users');
    if ($query === false) { throw new \RuntimeException('Cannot inspect account columns.'); }
    $columns = $query->fetchAll(\PDO::FETCH_COLUMN);
    if (in_array('avatar_appearance', $columns, true)) { $pdo->exec('ALTER TABLE users DROP COLUMN avatar_appearance'); }
};
