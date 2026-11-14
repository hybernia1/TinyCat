<?php
declare(strict_types=1);

// Immutable module version-one schema.
return static function (\PDO $pdo): void {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(32) NOT NULL UNIQUE,
                email VARCHAR(254) UNIQUE, email_notifications INTEGER NOT NULL DEFAULT 1,
                password VARCHAR(255), role VARCHAR(40) NOT NULL DEFAULT 'user',
                status VARCHAR(20) NOT NULL DEFAULT 'active', locale VARCHAR(12),
                theme VARCHAR(12) NOT NULL DEFAULT 'system', avatar_exists INTEGER NOT NULL DEFAULT 0,
                avatar_appearance TEXT, bio VARCHAR(500), muted_until DATETIME,
                muted_by INTEGER REFERENCES users(id) ON DELETE SET NULL, muted_reason VARCHAR(80),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS users_role_status_index ON users(role, status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS users_status_username_index ON users(status, username, id)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS password_reset_tokens_user_index ON password_reset_tokens(user_id, expires_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS password_reset_tokens_expiry_index ON password_reset_tokens(expires_at)');
            return;
        }
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
                avatar_appearance TEXT NULL,
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
};
