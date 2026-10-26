<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    $mysql = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $columns = $database->query('SHOW COLUMNS FROM bot_source_runs')->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $columns = array_column($database->query('PRAGMA table_info(bot_source_runs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
    if (!in_array('scheduler_run_id', $columns, true)) {
        $database->exec('ALTER TABLE bot_source_runs ADD COLUMN scheduler_run_id BIGINT NULL');
    }
    // Histories have independent retention; this reference deliberately has no foreign key.
    if ($mysql) {
        $indexes = $database->query('SHOW INDEX FROM bot_source_runs')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('bot_source_runs_scheduler_index', array_column($indexes, 'Key_name'), true)) {
            $database->exec('CREATE INDEX bot_source_runs_scheduler_index ON bot_source_runs (scheduler_run_id, id)');
        }
    } else {
        $database->exec('CREATE INDEX IF NOT EXISTS bot_source_runs_scheduler_index ON bot_source_runs (scheduler_run_id, id)');
    }
};
