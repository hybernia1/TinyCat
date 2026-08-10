<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The user activity timestamp removal migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $columnExists = $database->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );

    foreach (['last_seen_at', 'last_login_at'] as $column) {
        $columnExists->execute([$schema, 'users', $column]);

        if ($columnExists->fetchColumn() === false) {
            continue;
        }

        if ($database->exec('ALTER TABLE users DROP COLUMN ' . $column) === false) {
            throw new RuntimeException('Unable to remove the obsolete user activity timestamp: ' . $column);
        }
    }
};
