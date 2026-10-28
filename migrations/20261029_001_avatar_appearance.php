<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    $column = $database->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $column->execute(['users', 'avatar_appearance']);
    if ($column->fetchColumn() === false && $database->exec('ALTER TABLE users ADD COLUMN avatar_appearance TEXT NULL AFTER avatar_exists') === false) {
        throw new RuntimeException('Unable to add avatar_appearance.');
    }
};
