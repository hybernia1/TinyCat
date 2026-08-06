<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The comment history migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();

    if ($schema === '') {
        throw new RuntimeException('Unable to resolve the active database schema.');
    }

    $statement = $database->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $statement->execute([$schema, 'content_comments', 'comments_diff']);

    if ($statement->fetchColumn() !== false) {
        return;
    }

    $result = $database->exec('ALTER TABLE content_comments ADD COLUMN comments_diff JSON NULL AFTER body');

    if ($result === false) {
        throw new RuntimeException('Unable to add the comment history column.');
    }
};
