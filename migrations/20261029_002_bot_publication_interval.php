<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    $column = $database->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $column->execute(['bot_sources', 'publication_interval_minutes']);
    if ($column->fetchColumn() === false) {
        $database->exec('ALTER TABLE bot_sources ADD COLUMN publication_interval_minutes INT UNSIGNED NOT NULL DEFAULT 30 AFTER interval_minutes');
    }
    $database->exec('UPDATE bot_sources SET publication_interval_minutes = 30 WHERE publication_interval_minutes IS NULL OR publication_interval_minutes < 5 OR publication_interval_minutes > 43200');
};
