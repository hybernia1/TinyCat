<?php
declare(strict_types=1);

// Current fresh-install DDL; historical migrations are immutable.
return [
    "CREATE TABLE IF NOT EXISTS user_followers (
                user_id INT UNSIGNED NOT NULL,
                follower_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, follower_id),
                KEY user_followers_follower_recent_index (follower_id, created_at DESC, user_id ASC),
                CONSTRAINT fk_user_followers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_followers_follower FOREIGN KEY (follower_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
