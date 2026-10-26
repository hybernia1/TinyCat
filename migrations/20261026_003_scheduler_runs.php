<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    $mysql = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $database->exec('CREATE TABLE IF NOT EXISTS scheduler_runs (
        id ' . $id . ', task VARCHAR(40) NOT NULL, mode VARCHAR(16) NOT NULL,
        actor_id BIGINT NULL, status VARCHAR(24) NOT NULL,
        started_at DATETIME NOT NULL, finished_at DATETIME NULL,
        duration_ms BIGINT NULL, options_json TEXT NOT NULL,
        summary_json TEXT NULL, error TEXT NULL' . ($mysql ? ', KEY scheduler_runs_task_id (task, id), KEY scheduler_runs_started (started_at, id)' : '') . '
    )' . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    if (!$mysql) {
        $database->exec('CREATE INDEX IF NOT EXISTS scheduler_runs_task_id ON scheduler_runs (task, id)');
        $database->exec('CREATE INDEX IF NOT EXISTS scheduler_runs_started ON scheduler_runs (started_at, id)');
    }
};
