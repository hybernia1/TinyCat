<?php
declare(strict_types=1);

// Immutable migration snapshot; current schema helpers may evolve independently.
return static function (\PDO $pdo): void {
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL, content_id INTEGER, comment_id INTEGER, type VARCHAR(40) NOT NULL, notification_key VARCHAR(190) NOT NULL, payload_json TEXT, read_at DATETIME, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, notification_key))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS notifications_user_index ON notifications(user_id, read_at, created_at)');
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, actor_id INT UNSIGNED NULL, content_id BIGINT UNSIGNED NULL, comment_id BIGINT UNSIGNED NULL, type VARCHAR(40) NOT NULL, notification_key VARCHAR(190) NOT NULL, payload_json LONGTEXT NULL, read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY notifications_key_unique(user_id, notification_key), KEY notifications_user_index(user_id, read_at, created_at), KEY notifications_actor_index(actor_id, created_at), KEY notifications_content_index(content_id), KEY notifications_comment_index(comment_id), CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_notifications_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};
