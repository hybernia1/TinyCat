<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The link embed URL removal migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $statement = $database->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $statement->execute([$schema, 'links', 'embed_url']);

    if ($statement->fetchColumn() === false) {
        return;
    }

    if ($database->exec('ALTER TABLE links DROP COLUMN embed_url') === false) {
        throw new RuntimeException('Unable to remove the redundant link embed URL.');
    }
};
