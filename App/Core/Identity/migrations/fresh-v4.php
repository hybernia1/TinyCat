<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(32) NOT NULL,
                email VARCHAR(254) NULL,
                email_notifications TINYINT(1) NOT NULL DEFAULT 1,
                password VARCHAR(255) NULL,
                role VARCHAR(40) NOT NULL DEFAULT 'user',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                locale VARCHAR(12) NULL,
                theme VARCHAR(12) NOT NULL DEFAULT 'system',
                avatar_exists TINYINT(1) NOT NULL DEFAULT 0,
                avatar_version VARCHAR(32) NULL,
                bio VARCHAR(500) NULL,
                muted_until DATETIME NULL,
                muted_by INT UNSIGNED NULL,
                muted_reason VARCHAR(80) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY users_username_unique (username),
                UNIQUE KEY users_email_unique (email),
                KEY users_role_status_index (role, status),
                KEY users_status_username_index (status, username, id),
                CONSTRAINT fk_users_muted_by FOREIGN KEY (muted_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY password_reset_tokens_hash_unique (token_hash),
                KEY password_reset_tokens_user_index (user_id, expires_at),
                KEY password_reset_tokens_expiry_index (expires_at),
                CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $indexes = require __DIR__ . '/read-indexes.php';
    $indexes($pdo);
};
