<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    $sqlite = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $columns = $sqlite
        ? array_column($database->query('PRAGMA table_info(content)')->fetchAll(PDO::FETCH_ASSOC), 'name')
        : $database->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content'")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('status', $columns, true)) {
        $database->exec("ALTER TABLE content ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'published'");
    }
    if (in_array('edit_lock_reason', $columns, true)) {
        $database->exec("UPDATE content SET status = 'hidden' WHERE edit_lock_reason = 'moderation_hide'");
    }
    if (!$sqlite && in_array('edit_locked_by', $columns, true)) {
        $constraints = $database->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content' AND COLUMN_NAME = 'edit_locked_by' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($constraints as $constraint) {
            $database->exec('ALTER TABLE content DROP FOREIGN KEY `' . str_replace('`', '``', (string) $constraint) . '`');
        }
    }
    $lockColumns = array_intersect(['edit_locked_at', 'edit_locked_by', 'edit_lock_reason'], $columns);
    if ($sqlite && $lockColumns !== []) {
        // Rebuild the parent without its named lock foreign key. Keep child
        // references pointed at content and prevent ON DELETE cascades during DDL.
        $foreignKeys = (int) $database->query('PRAGMA foreign_keys')->fetchColumn();
        $database->exec('PRAGMA foreign_keys = OFF');
        $database->beginTransaction();
        try {
            $database->exec("CREATE TABLE content_status_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT, body VARCHAR(2000) NOT NULL,
                author_id INTEGER NOT NULL, published_at DATETIME NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'published',
                FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
            )");
            $database->exec('INSERT INTO content_status_migration (id, body, author_id, published_at, status) SELECT id, body, author_id, published_at, status FROM content');
            $database->exec('DROP TABLE content');
            $database->exec('ALTER TABLE content_status_migration RENAME TO content');
            $database->exec('CREATE INDEX content_feed_index ON content (published_at, id)');
            $database->exec('CREATE INDEX content_sidebar_index ON content (published_at, author_id, id)');
            $database->exec('CREATE INDEX content_author_index ON content (author_id, published_at, id)');
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) { $database->rollBack(); }
            throw $exception;
        } finally {
            $database->exec('PRAGMA foreign_keys = ' . $foreignKeys);
        }
    } elseif (!$sqlite) {
        foreach ($lockColumns as $column) { $database->exec('ALTER TABLE content DROP COLUMN ' . $column); }
    }

    if ($sqlite) {
        $indexes = $database->query('PRAGMA index_list(content_reports)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if (!(bool) $index['unique']) { continue; }
            $name = str_replace('"', '""', (string) $index['name']);
            $indexColumns = array_column($database->query('PRAGMA index_info("' . $name . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if ($indexColumns !== ['content_id', 'reporter_id']) { continue; }
            if ($index['origin'] !== 'u') {
                $database->exec('DROP INDEX "' . $name . '"');
                continue;
            }
            // SQLite cannot drop an inline UNIQUE constraint. Rebuild only this
            // child table, preserving all report rows and their foreign keys.
            $database->beginTransaction();
            try {
                $database->exec("CREATE TABLE content_reports_status_migration (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, content_id INTEGER NOT NULL,
                    reporter_id INTEGER, reason VARCHAR(40) NOT NULL DEFAULT 'other', note TEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    reviewed_at DATETIME, reviewed_by INTEGER, action_note TEXT,
                    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
                    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
                    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
                )");
                $fields = 'id, content_id, reporter_id, reason, note, status, created_at, reviewed_at, reviewed_by, action_note';
                $database->exec('INSERT INTO content_reports_status_migration (' . $fields . ') SELECT ' . $fields . ' FROM content_reports');
                $database->exec('DROP TABLE content_reports');
                $database->exec('ALTER TABLE content_reports_status_migration RENAME TO content_reports');
                $database->commit();
            } catch (Throwable $exception) {
                if ($database->inTransaction()) { $database->rollBack(); }
                throw $exception;
            }
            break;
        }
        $database->exec('CREATE INDEX IF NOT EXISTS content_reports_content_reporter_status_index ON content_reports (content_id, reporter_id, status)');
        $database->exec('CREATE INDEX IF NOT EXISTS content_reports_status_index ON content_reports (status, created_at)');
        $database->exec('CREATE INDEX IF NOT EXISTS content_reports_reporter_index ON content_reports (reporter_id)');
    } else {
        $indexes = array_column($database->query('SHOW INDEX FROM content_reports')->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
        if (!in_array('content_reports_content_reporter_status_index', $indexes, true)) {
            $database->exec('ALTER TABLE content_reports ADD KEY content_reports_content_reporter_status_index (content_id, reporter_id, status)');
        }
        if (in_array('content_reports_unique', $indexes, true)) {
            $database->exec('ALTER TABLE content_reports DROP INDEX content_reports_unique');
        }
    }
};
