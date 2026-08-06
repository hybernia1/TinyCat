<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The content images migration requires MySQL or MariaDB.');
    }

    $result = $database->exec(
        'CREATE TABLE IF NOT EXISTS content_images (
            content_id BIGINT UNSIGNED NOT NULL,
            path VARCHAR(190) NOT NULL,
            width INT UNSIGNED NOT NULL,
            height INT UNSIGNED NOT NULL,
            bytes INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (content_id),
            UNIQUE KEY content_images_path_unique (path),
            CONSTRAINT fk_content_images_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if ($result === false) {
        throw new RuntimeException('Unable to create the content images table.');
    }
};
