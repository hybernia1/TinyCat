<?php
declare(strict_types=1);

// Immutable core task-history baseline. Existing IDs and rows are retained.
return static function (\PDO $pdo): void {
    $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $exists = $pdo->query($sqlite ? "SELECT name FROM sqlite_master WHERE name = 'module_installations'" : "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_installations'");
    if ($exists !== false && $exists->fetchColumn() !== false) {
        $state = $pdo->query("SELECT version, status FROM module_installations WHERE module_id = 'tinycat.scheduler'");
        $legacy = $state === false ? false : $state->fetch(\PDO::FETCH_ASSOC);
        if ($legacy !== false) {
            $history = $pdo->query("SELECT migration, version, checksum FROM module_migrations WHERE module_id = 'tinycat.scheduler' ORDER BY version");
            $rows = $history === false ? [] : $history->fetchAll(\PDO::FETCH_ASSOC);
            if ((int) $legacy['version'] !== 1 || $legacy['status'] !== 'ready' || count($rows) !== 1 || $rows[0]['migration'] !== 'tinycat.scheduler.initial' || (int) $rows[0]['version'] !== 1 || $rows[0]['checksum'] !== 'caeaae1eb91853db1a83e73afd2e17830e79877dae46b1989bcaf8c2e59f130d') { throw new \RuntimeException('Legacy task-history migration integrity mismatch.'); }
        }
    }
    $statements = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? [
'CREATE TABLE IF NOT EXISTS scheduler_runs (
                id INTEGER NOT NULL PRIMARY KEY,
                task VARCHAR(40) NOT NULL, mode VARCHAR(16) NOT NULL,
                actor_id BIGINT NULL, status VARCHAR(24) NOT NULL,
                started_at DATETIME NOT NULL, finished_at DATETIME NULL,
                duration_ms BIGINT NULL, options_json TEXT NOT NULL,
                summary_json TEXT NULL, error TEXT NULL
)',
'CREATE INDEX IF NOT EXISTS scheduler_runs_task_id ON scheduler_runs(task, id)',
'CREATE INDEX IF NOT EXISTS scheduler_runs_started ON scheduler_runs(started_at, id)'
    ] : [
'CREATE TABLE IF NOT EXISTS scheduler_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task VARCHAR(40) NOT NULL, mode VARCHAR(16) NOT NULL,
                actor_id BIGINT NULL, status VARCHAR(24) NOT NULL,
                started_at DATETIME NOT NULL, finished_at DATETIME NULL,
                duration_ms BIGINT NULL, options_json TEXT NOT NULL,
                summary_json TEXT NULL, error TEXT NULL,
                KEY scheduler_runs_task_id (task, id),
                KEY scheduler_runs_started (started_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ];
    foreach ($statements as $sql) { $pdo->exec($sql); }
    $query = $pdo->query($sqlite ? 'PRAGMA table_info(scheduler_runs)' : "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_runs'");
    if ($query === false || array_diff(['id', 'task', 'mode', 'actor_id', 'status', 'started_at', 'finished_at', 'duration_ms', 'options_json', 'summary_json', 'error'], array_column($query->fetchAll(\PDO::FETCH_ASSOC), 'name')) !== []) { throw new \RuntimeException('Incompatible task-history storage.'); }
};
