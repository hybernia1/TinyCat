<?php
declare(strict_types=1);

// Immutable standalone baseline.
return static function (\PDO $pdo): void {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS user_followers (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, follower_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id, follower_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS user_followers_follower_recent_index ON user_followers(follower_id, created_at DESC, user_id ASC)');
            return;
        }
        foreach (require __DIR__ . '/install-schema.mysql.php' as $sql) { $pdo->exec($sql); }

};
