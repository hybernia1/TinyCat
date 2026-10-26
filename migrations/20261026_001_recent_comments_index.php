<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The recent-comments index migration requires MySQL or MariaDB.');
    }
    $exists = $database->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $exists->execute(['content_comments', 'content_comments_recent_index']);
    if ($exists->fetchColumn() === false && $database->exec(
        'ALTER TABLE content_comments ADD INDEX content_comments_recent_index (created_at, id)'
    ) === false) {
        throw new RuntimeException('Unable to add the recent-comments index.');
    }
};
