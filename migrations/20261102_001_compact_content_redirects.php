<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    $sqlite = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $tableExists = static function (string $name) use ($database, $sqlite): bool {
        $query = $database->prepare($sqlite ? "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"
            : 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$name]); return $query->fetchColumn() !== false;
    };
    $exists = $tableExists('content_redirects');
    if (!$exists && $tableExists('content_redirects_compact')) {
        $database->exec('ALTER TABLE content_redirects_compact RENAME TO content_redirects');
        $exists = true;
    }
    $ensureIndex = static function () use ($database, $sqlite): void {
        $indexes = $sqlite ? array_column($database->query('PRAGMA index_list(content_redirects)')->fetchAll(PDO::FETCH_ASSOC), 'name')
            : array_column($database->query('SHOW INDEX FROM content_redirects')->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
        if (!in_array('content_redirects_target_index', $indexes, true)) {
            $database->exec('CREATE INDEX content_redirects_target_index ON content_redirects (content_id)');
        }
    };
    $columns = !$exists ? [] : ($sqlite
        ? array_column($database->query('PRAGMA table_info(content_redirects)')->fetchAll(PDO::FETCH_ASSOC), 'name')
        : $database->query('SHOW COLUMNS FROM content_redirects')->fetchAll(PDO::FETCH_COLUMN));
    if ($columns === ['old_id', 'content_id']) { $ensureIndex(); return; }
    if ($exists && (!in_array('old_id', $columns, true) || !in_array('content_id', $columns, true))) {
        throw new RuntimeException('Unexpected content_redirects schema.');
    }
    $table = $exists ? 'content_redirects_compact' : 'content_redirects';
    if ($exists && $tableExists($table)) { $database->exec('DROP TABLE content_redirects_compact'); }
    $database->exec('CREATE TABLE ' . $table . ' (
        old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        content_id BIGINT UNSIGNED NOT NULL,
        CONSTRAINT fk_redirect_target_post FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
    )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    if ($exists) {
        // Preserve mappings created by the earlier, unreleased review UI.
        $database->exec('INSERT INTO ' . $table . ' (old_id,content_id) SELECT r.old_id,r.content_id FROM content_redirects r INNER JOIN content c ON c.id=r.content_id WHERE r.old_id<>r.content_id');
        $database->exec('DROP TABLE content_redirects');
        $database->exec('ALTER TABLE ' . $table . ' RENAME TO content_redirects');
    }
    $ensureIndex();
};
