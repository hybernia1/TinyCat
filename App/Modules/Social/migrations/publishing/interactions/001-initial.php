<?php
declare(strict_types=1);

// Immutable standalone baseline.
return static function (\PDO $database): void {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $database->exec('CREATE TABLE IF NOT EXISTS content_likes (content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, PRIMARY KEY(content_id, user_id))');
            $database->exec('CREATE INDEX IF NOT EXISTS content_likes_user_index ON content_likes(user_id, content_id)');
            $database->exec('CREATE TABLE IF NOT EXISTS content_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE, parent_id INTEGER REFERENCES content_comments(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, body VARCHAR(2000) NOT NULL, comments_diff TEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $database->exec('CREATE INDEX IF NOT EXISTS content_comments_content_index ON content_comments(content_id, parent_id, created_at)');
            $database->exec('CREATE INDEX IF NOT EXISTS content_comments_user_index ON content_comments(user_id, created_at)');
            $database->exec('CREATE INDEX IF NOT EXISTS content_comments_parent_index ON content_comments(parent_id)');
            $database->exec('CREATE INDEX IF NOT EXISTS content_comments_recent_index ON content_comments(created_at, id)');
            $database->exec('CREATE TABLE IF NOT EXISTS comment_likes (comment_id INTEGER NOT NULL REFERENCES content_comments(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, PRIMARY KEY(comment_id, user_id))');
            $database->exec('CREATE INDEX IF NOT EXISTS comment_likes_user_index ON comment_likes(user_id, comment_id)');
            return;
        }
        foreach ([
    "CREATE TABLE IF NOT EXISTS content_likes (
                content_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id, user_id),
                KEY content_likes_user_index (user_id, content_id),
                CONSTRAINT fk_content_likes_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS content_comments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                content_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                user_id INT UNSIGNED NOT NULL,
                body VARCHAR(2000) NOT NULL,
                comments_diff JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY content_comments_content_index (content_id, parent_id, created_at),
                KEY content_comments_user_index (user_id, created_at),
                KEY content_comments_parent_index (parent_id),
                KEY content_comments_recent_index (created_at, id),
                CONSTRAINT fk_content_comments_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_comments_parent FOREIGN KEY (parent_id) REFERENCES content_comments (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS comment_likes (
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (comment_id, user_id),
                KEY comment_likes_user_index (user_id, comment_id),
                CONSTRAINT fk_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES content_comments (id) ON DELETE CASCADE,
                CONSTRAINT fk_comment_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
] as $sql) { $database->exec($sql); }

};
