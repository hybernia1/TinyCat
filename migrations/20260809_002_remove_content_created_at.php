<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The content timestamp removal migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $statement = $database->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $statement->execute([$schema, 'content', 'created_at']);

    if ($statement->fetchColumn() === false) {
        return;
    }

    if ($database->exec('ALTER TABLE content DROP COLUMN created_at') === false) {
        throw new RuntimeException('Unable to remove the redundant content creation timestamp.');
    }
};
