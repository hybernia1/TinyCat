<?php
declare(strict_types=1);

// Immutable module version-one schema.
return static function (\PDO $pdo): void {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS ip_action_limits (ip_address VARCHAR(45) NOT NULL, action_name VARCHAR(40) NOT NULL, bucket_start DATETIME NOT NULL, action_count INTEGER NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(ip_address, action_name, bucket_start))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ip_action_limits_bucket_index ON ip_action_limits(bucket_start)');
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ip_action_limits (
                ip_address VARCHAR(45) NOT NULL,
                action_name VARCHAR(40) NOT NULL,
                bucket_start DATETIME NOT NULL,
                action_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (ip_address, action_name, bucket_start),
                KEY ip_action_limits_bucket_index (bucket_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
