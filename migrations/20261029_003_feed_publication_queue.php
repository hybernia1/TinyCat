<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database): void {
    $database->exec("CREATE TABLE IF NOT EXISTS bot_source_posts (
                content_id BIGINT UNSIGNED NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id),
                KEY bot_source_posts_source_index (source_id, content_id),
                CONSTRAINT fk_bot_source_posts_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_bot_source_posts_source FOREIGN KEY (source_id) REFERENCES bot_sources (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
