<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The content link position removal migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $columnExists = $database->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $columnExists->execute([$schema, 'content_links', 'position_index']);

    if ($columnExists->fetchColumn() === false) {
        return;
    }

    $indexExists = $database->prepare(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $indexExists->execute([$schema, 'content_links', 'content_links_content_index']);

    if ($indexExists->fetchColumn() !== false
        && $database->exec('ALTER TABLE content_links DROP INDEX content_links_content_index') === false
    ) {
        throw new RuntimeException('Unable to remove the obsolete content-link position index.');
    }

    if ($database->exec('ALTER TABLE content_links DROP COLUMN position_index') === false) {
        throw new RuntimeException('Unable to remove the obsolete content-link position column.');
    }
};
