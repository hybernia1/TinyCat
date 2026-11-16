<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    \TinyCat\Core\Tasks\Schema::validateLegacyHistory($pdo);
    $suffix = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $id = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER NOT NULL PRIMARY KEY';
    $pdo->exec('CREATE TABLE IF NOT EXISTS scheduler_runs (id ' . $id . ', task VARCHAR(40) NOT NULL, mode VARCHAR(16) NOT NULL, actor_id BIGINT NULL, status VARCHAR(24) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, duration_ms BIGINT NULL, options_json TEXT NOT NULL, summary_json TEXT NULL, error TEXT NULL)' . $suffix);
    foreach (['scheduler_runs_task_id' => 'task, id', 'scheduler_runs_started' => 'started_at, id'] as $name => $columns) {
        $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if (!$sqlite) { $query = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'); $query->execute(['scheduler_runs', $name]); if ($query->fetchColumn() !== false) { continue; } }
        $pdo->exec('CREATE INDEX ' . ($sqlite ? 'IF NOT EXISTS ' : '') . $name . ' ON scheduler_runs(' . $columns . ')');
    }
    \TinyCat\Database\Schema::requireColumns($pdo, 'scheduler_runs', ['id', 'task', 'mode', 'actor_id', 'status', 'started_at', 'finished_at', 'duration_ms', 'options_json', 'summary_json', 'error']);
};
