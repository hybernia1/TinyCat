<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $userId = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $auto = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_sources (id ' . $auto . ', author_id ' . $userId . ' NOT NULL, name VARCHAR(120) NOT NULL, feed_url VARCHAR(2048) NOT NULL, feed_hash CHAR(64) NOT NULL UNIQUE, interval_minutes INTEGER NOT NULL DEFAULT 60, publication_status VARCHAR(16) NOT NULL DEFAULT \'hidden\', import_images INTEGER NOT NULL DEFAULT 0, enabled INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, next_run_at DATETIME NULL, last_checked_at DATETIME NULL, last_imported_at DATETIME NULL, last_error VARCHAR(500) NULL, FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE' . ($mysql ? ', INDEX blog_sources_due (enabled, next_run_at, id)' : '') . ')' . $suffix);
    if (!$mysql) { $pdo->exec('CREATE INDEX IF NOT EXISTS blog_sources_due ON blog_sources(enabled, next_run_at, id)'); }
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_feed_history (source_id ' . $id . ' NOT NULL, item_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (source_id, item_hash), FOREIGN KEY (source_id) REFERENCES blog_sources(id) ON DELETE CASCADE)' . $suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_source_articles (source_id ' . $id . ' NOT NULL, content_id ' . $id . ' NOT NULL UNIQUE, PRIMARY KEY (source_id, content_id), FOREIGN KEY (source_id) REFERENCES blog_sources(id) ON DELETE CASCADE, FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE)' . $suffix);
};
