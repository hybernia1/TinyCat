<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS bot_sources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            feed_url VARCHAR(2048) NOT NULL,
            feed_hash CHAR(64) NOT NULL,
            interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            post_template VARCHAR(2000) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_checked_at DATETIME NULL,
            last_imported_at DATETIME NULL,
            next_run_at DATETIME NULL,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bot_sources_feed_hash_unique (feed_hash),
            KEY bot_sources_due_index (enabled, next_run_at, id),
            KEY bot_sources_user_index (bot_user_id, id),
            CONSTRAINT fk_bot_sources_user FOREIGN KEY (bot_user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS bot_feed_history (
            bot_user_id INT UNSIGNED NOT NULL,
            feed_hash CHAR(64) NOT NULL,
            item_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bot_user_id, feed_hash, item_hash),
            KEY bot_feed_history_created_index (created_at),
            CONSTRAINT fk_bot_feed_history_user FOREIGN KEY (bot_user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS bot_source_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL,
            started_at DATETIME NOT NULL,
            items_seen INT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(500) NULL,
            PRIMARY KEY (id),
            KEY bot_source_runs_source_index (source_id, started_at),
            KEY bot_source_runs_status_index (status, started_at),
            CONSTRAINT fk_bot_source_runs_source FOREIGN KEY (source_id) REFERENCES bot_sources (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $statement) {
        $database->exec($statement);
    }

    (static function (PDO $database): void {
        $hasColumn = static function (string $table, string $column) use ($database): bool {
            $statement = $database->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $statement->execute([$table, $column]);
            return (int) $statement->fetchColumn() > 0;
        };
        $hasIndex = static function (string $table, string $index) use ($database): bool {
            $statement = $database->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $statement->execute([$table, $index]);
            return (int) $statement->fetchColumn() > 0;
        };
        $hasForeignKey = static function (string $table, string $constraint) use ($database): bool {
            $statement = $database->prepare(
                'SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?'
            );
            $statement->execute([$table, $constraint, 'FOREIGN KEY']);
            return (int) $statement->fetchColumn() > 0;
        };

        if ($hasForeignKey('bot_feed_items', 'fk_bot_feed_items_content')) {
            $database->exec('ALTER TABLE bot_feed_items DROP FOREIGN KEY fk_bot_feed_items_content');
        }
        if ($hasIndex('bot_feed_items', 'bot_feed_items_content_index')) {
            $database->exec('ALTER TABLE bot_feed_items DROP INDEX bot_feed_items_content_index');
        }
        if ($hasIndex('bot_feed_items', 'bot_feed_items_created_index')) {
            $database->exec('ALTER TABLE bot_feed_items DROP INDEX bot_feed_items_created_index');
        }

        $itemColumns = [];
        foreach (['content_id', 'item_guid', 'item_published_at', 'created_at'] as $column) {
            if ($hasColumn('bot_feed_items', $column)) {
                $itemColumns[] = 'DROP COLUMN ' . $column;
            }
        }
        if ($itemColumns !== []) {
            $database->exec('ALTER TABLE bot_feed_items ' . implode(', ', $itemColumns));
        }

        if ($hasForeignKey('bot_feed_history', 'fk_bot_feed_history_content')) {
            $database->exec('ALTER TABLE bot_feed_history DROP FOREIGN KEY fk_bot_feed_history_content');
        }
        if ($hasIndex('bot_feed_history', 'bot_feed_history_content_index')) {
            $database->exec('ALTER TABLE bot_feed_history DROP INDEX bot_feed_history_content_index');
        }

        $historyColumns = [];
        foreach (['content_id', 'item_guid', 'item_published_at'] as $column) {
            if ($hasColumn('bot_feed_history', $column)) {
                $historyColumns[] = 'DROP COLUMN ' . $column;
            }
        }
        if ($historyColumns !== []) {
            $database->exec('ALTER TABLE bot_feed_history ' . implode(', ', $historyColumns));
        }

        if (!$hasIndex('bot_source_runs', 'bot_source_runs_started_index')) {
            $database->exec('ALTER TABLE bot_source_runs ADD KEY bot_source_runs_started_index (started_at, id)');
        }

        $database->exec("DELETE FROM bot_source_runs WHERE status = 'current' AND started_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
        $database->exec('DELETE FROM bot_source_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 14 DAY)');
        $database->exec(
            'DELETE FROM bot_feed_history
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM bot_sources
                    WHERE bot_sources.bot_user_id = bot_feed_history.bot_user_id
                        AND bot_sources.feed_hash = bot_feed_history.feed_hash
                )'
        );

        $historyFeeds = $database->query(
            'SELECT DISTINCT bot_user_id, feed_hash
             FROM bot_feed_history
             ORDER BY bot_user_id ASC, feed_hash ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $pruneFeedHistory = $database->prepare(
            "DELETE FROM bot_feed_history
             WHERE bot_user_id = ? AND feed_hash = ?
                AND item_hash NOT IN (
                    SELECT item_hash FROM (
                        SELECT item_hash
                        FROM bot_feed_history
                        WHERE bot_user_id = ? AND feed_hash = ?
                        ORDER BY created_at DESC, item_hash DESC
                        LIMIT 100
                    ) retained_items
                )"
        );
        foreach ($historyFeeds as $historyFeed) {
            $botUserId = (int) ($historyFeed['bot_user_id'] ?? 0);
            $feedHash = (string) ($historyFeed['feed_hash'] ?? '');
            if ($botUserId > 0 && preg_match('/^[a-f0-9]{64}$/', $feedHash)) {
                $pruneFeedHistory->execute([$botUserId, $feedHash, $botUserId, $feedHash]);
            }
        }

        $sourceIds = $database->query('SELECT id FROM bot_sources ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
        $pruneCurrentRuns = $database->prepare(
            "DELETE FROM bot_source_runs
             WHERE source_id = ? AND status = 'current'
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id
                        FROM bot_source_runs
                        WHERE source_id = ? AND status = 'current'
                        ORDER BY started_at DESC, id DESC
                        LIMIT 250
                    ) retained_runs
                )"
        );
        foreach ($sourceIds as $sourceId) {
            $pruneCurrentRuns->execute([(int) $sourceId, (int) $sourceId]);
        }
    })($database);

    (static function (PDO $database): void {
        $hasTable = static function (string $table) use ($database): bool {
            $statement = $database->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $statement->execute([$table]);

            return (int) $statement->fetchColumn() > 0;
        };
        $hasColumn = static function (string $table, string $column) use ($database): bool {
            $statement = $database->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $statement->execute([$table, $column]);

            return (int) $statement->fetchColumn() > 0;
        };
        $hasIndex = static function (string $table, string $index) use ($database): bool {
            $statement = $database->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $statement->execute([$table, $index]);

            return (int) $statement->fetchColumn() > 0;
        };
        $hasForeignKey = static function (string $table, string $foreignKey) use ($database): bool {
            $statement = $database->prepare(
                'SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?'
            );
            $statement->execute([$table, $foreignKey, 'FOREIGN KEY']);

            return (int) $statement->fetchColumn() > 0;
        };

        if ($hasTable('bot_feed_items')) {
            $database->exec(
                'INSERT IGNORE INTO bot_feed_history (bot_user_id, feed_hash, item_hash, created_at)
                 SELECT bot_sources.bot_user_id, bot_sources.feed_hash, bot_feed_items.item_hash, NOW()
                 FROM bot_feed_items
                 INNER JOIN bot_sources ON bot_sources.id = bot_feed_items.source_id'
            );
            $database->exec('DROP TABLE bot_feed_items');
        }

        $pruneHistory = $database->prepare(
            'DELETE FROM bot_feed_history
             WHERE bot_user_id = ? AND feed_hash = ?
                AND item_hash NOT IN (
                    SELECT item_hash FROM (
                        SELECT item_hash
                        FROM bot_feed_history
                        WHERE bot_user_id = ? AND feed_hash = ?
                        ORDER BY created_at DESC, item_hash DESC
                        LIMIT 500
                    ) retained_items
                )'
        );
        foreach ($database->query('SELECT DISTINCT bot_user_id, feed_hash FROM bot_feed_history')->fetchAll(PDO::FETCH_ASSOC) as $history) {
            $pruneHistory->execute([
                (int) $history['bot_user_id'],
                (string) $history['feed_hash'],
                (int) $history['bot_user_id'],
                (string) $history['feed_hash'],
            ]);
        }

        if ($hasForeignKey('bot_source_runs', 'fk_bot_source_runs_user')) {
            $database->exec('ALTER TABLE bot_source_runs DROP FOREIGN KEY fk_bot_source_runs_user');
        }
        if ($hasForeignKey('bot_source_runs', 'fk_bot_source_runs_content')) {
            $database->exec('ALTER TABLE bot_source_runs DROP FOREIGN KEY fk_bot_source_runs_content');
        }
        if ($hasIndex('bot_source_runs', 'bot_source_runs_bot_index')) {
            $database->exec('ALTER TABLE bot_source_runs DROP INDEX bot_source_runs_bot_index');
        }
        $runColumns = [];
        foreach (['bot_user_id', 'finished_at', 'items_imported', 'content_id', 'http_status', 'created_at'] as $column) {
            if ($hasColumn('bot_source_runs', $column)) {
                $runColumns[] = 'DROP COLUMN ' . $column;
            }
        }
        if ($runColumns !== []) {
            $database->exec('ALTER TABLE bot_source_runs ' . implode(', ', $runColumns));
        }
        if ($hasColumn('bot_sources', 'updated_at')) {
            $database->exec('ALTER TABLE bot_sources DROP COLUMN updated_at');
        }

        $database->exec("DELETE FROM bot_source_runs WHERE status = 'error' AND started_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        $pruneRuns = $database->prepare(
            "DELETE FROM bot_source_runs
             WHERE source_id = ? AND status <> 'error'
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id
                        FROM bot_source_runs
                        WHERE source_id = ? AND status <> 'error'
                        ORDER BY started_at DESC, id DESC
                        LIMIT 50
                    ) retained_runs
                )"
        );
        foreach ($database->query('SELECT id FROM bot_sources ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) as $sourceId) {
            $pruneRuns->execute([(int) $sourceId, (int) $sourceId]);
        }
    })($database);
};
