<?php
declare(strict_types=1);

// Standalone current fresh-install schema.
return static function (\PDO $pdo): void {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS user_followers (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, follower_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id, follower_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS user_followers_follower_recent_index ON user_followers(follower_id, created_at DESC, user_id ASC)');
            return;
        }
        foreach ([
    "CREATE TABLE IF NOT EXISTS user_followers (
                user_id INT UNSIGNED NOT NULL,
                follower_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, follower_id),
                KEY user_followers_follower_recent_index (follower_id, created_at DESC, user_id ASC),
                CONSTRAINT fk_user_followers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_followers_follower FOREIGN KEY (follower_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
] as $sql) { $pdo->exec($sql); }
};
