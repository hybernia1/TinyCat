<?php
declare(strict_types=1);

// Immutable publication-kind upgrade; legacy records keep their post identity.
return static function (\PDO $pdo): void {
    $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $query = $pdo->query($sqlite ? 'PRAGMA table_info(content)' : "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content'");
    if ($query === false) { throw new \RuntimeException('Cannot inspect publication schema.'); }
    $columns = array_column($query->fetchAll(\PDO::FETCH_ASSOC), 'name');
    if ($columns === []) { return; }
    if (!in_array('kind', $columns, true)) { $pdo->exec("ALTER TABLE content ADD COLUMN kind VARCHAR(40) NOT NULL DEFAULT 'post'"); }
    $query = $pdo->query($sqlite ? 'PRAGMA index_list(content)' : 'SHOW INDEX FROM content');
    if ($query === false) { throw new \RuntimeException('Cannot inspect publication indexes.'); }
    $indexes = $query->fetchAll(\PDO::FETCH_ASSOC);
    if (!in_array('content_kind_feed_index', array_column($indexes, $sqlite ? 'name' : 'Key_name'), true)) { $pdo->exec('CREATE INDEX content_kind_feed_index ON content(kind, published_at, id)'); }
};
