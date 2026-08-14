<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The relation timestamp removal migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $columnExists = $database->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );

    foreach (['content_images', 'content_likes', 'comment_likes'] as $table) {
        $columnExists->execute([$schema, $table, 'created_at']);

        if ($columnExists->fetchColumn() === false) {
            continue;
        }

        if ($database->exec('ALTER TABLE ' . $table . ' DROP COLUMN created_at') === false) {
            throw new RuntimeException('Unable to remove the redundant creation timestamp from ' . $table . '.');
        }
    }
};
