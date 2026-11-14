<?php
declare(strict_types=1);

// Immutable standalone baseline.
return static function (\PDO $pdo): void {
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
};
