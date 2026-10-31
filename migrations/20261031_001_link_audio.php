<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $columns = $database->query('PRAGMA table_info(links)')->fetchAll(PDO::FETCH_ASSOC);
        if (in_array('audio_url', array_column($columns, 'name'), true)) { return; }
    } else {
        $statement = $database->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute(['links', 'audio_url']);
        if ($statement->fetchColumn() !== false) { return; }
    }
    $database->exec('ALTER TABLE links ADD COLUMN audio_url VARCHAR(2048) NULL');
};
