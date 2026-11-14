<?php
declare(strict_types=1);

// Immutable standalone baseline.
return static function (\PDO $pdo): void {
    $statements = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? [
'CREATE TABLE IF NOT EXISTS settings (
                id INTEGER NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_group VARCHAR(60) NOT NULL DEFAULT \'general\',
                setting_value LONGTEXT NULL,
                setting_type VARCHAR(20) NOT NULL DEFAULT \'string\',
                autoload INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE (setting_key)
)',
'CREATE INDEX IF NOT EXISTS settings_group_index ON settings(setting_group)',
'CREATE INDEX IF NOT EXISTS settings_autoload_index ON settings(autoload)'
    ] : [
'CREATE TABLE IF NOT EXISTS settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(120) NOT NULL,
                setting_group VARCHAR(60) NOT NULL DEFAULT \'general\',
                setting_value LONGTEXT NULL,
                setting_type VARCHAR(20) NOT NULL DEFAULT \'string\',
                autoload TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY settings_key_unique (setting_key),
                KEY settings_group_index (setting_group),
                KEY settings_autoload_index (autoload)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ];
    foreach ($statements as $sql) { $pdo->exec($sql); }
};
