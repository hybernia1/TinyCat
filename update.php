<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('TINYCAT')) {
    http_response_code(403);
    exit("Run this file from the command line or through the admin updater.\n");
}

if (PHP_SAPI === 'cli' && !defined('TINYCAT')) {
    define('TINYCAT', true);
}

if (!function_exists('db')) {
    require_once __DIR__ . '/App/functions.php';
}

function tinycat_run_updates(): array
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(80) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $appliedVersions = array_fill_keys(
        array_map('strval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)),
        true
    );
    $applied = [];

    foreach (tinycat_migrations() as $version => $migration) {
        if (isset($appliedVersions[$version])) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $migration($pdo);
            $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $pdo->commit();
            $applied[] = $version;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    return [
        'version' => $applied !== [] ? end($applied) : (string) array_key_last(tinycat_migrations()),
        'applied' => $applied !== [],
        'message' => $applied === []
            ? 'Already up to date.'
            : 'Applied migrations: ' . implode(', ', $applied),
    ];
}

/** @return array<string, Closure(PDO): void> */
function tinycat_migrations(): array
{
    return [
        '20260731_email_analytics_ip_limits' => static function (PDO $pdo): void {
            if (!tinycat_schema_column_exists($pdo, 'users', 'email')) {
                $pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(254) NULL AFTER username');
            }
            if (!tinycat_schema_column_exists($pdo, 'users', 'email_notifications')) {
                $pdo->exec('ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1 AFTER email');
            }
            if (!tinycat_schema_unique_column_index_exists($pdo, 'users', 'email')) {
                $pdo->exec('ALTER TABLE users ADD UNIQUE KEY users_email_unique (email)');
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
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                template_key VARCHAR(80) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY email_templates_key_unique (template_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY password_reset_tokens_hash_unique (token_hash),
                KEY password_reset_tokens_user_index (user_id, expires_at),
                KEY password_reset_tokens_expiry_index (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $template = $pdo->prepare('INSERT IGNORE INTO email_templates (template_key, enabled) VALUES (?, 1)');
            foreach (email_template_keys() as $key) {
                $template->execute([$key]);
            }

            $settings = [
                ['email.smtp.host', '', 'string', 'email'],
                ['email.smtp.port', '587', 'int', 'email'],
                ['email.smtp.username', '', 'string', 'email'],
                ['email.smtp.password', '', 'string', 'email'],
                ['email.smtp.encryption', 'tls', 'string', 'email'],
                ['email.from_address', '', 'string', 'email'],
                ['email.from_name', 'TinyCat', 'string', 'email'],
                ['analytics.google_measurement_id', '', 'string', 'analytics'],
            ];
            $setting = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, setting_group) VALUES (?, ?, ?, ?)');
            foreach ($settings as $item) {
                $setting->execute($item);
            }
        },
        '20260731_bot_admin_v2' => static function (PDO $pdo): void {
            $pdo->exec("CREATE TABLE IF NOT EXISTS bot_sources (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                bot_user_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                feed_url VARCHAR(2048) NOT NULL,
                interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
                post_template VARCHAR(2000) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_checked_at DATETIME NULL,
                last_imported_at DATETIME NULL,
                next_run_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY bot_sources_due_index (enabled, next_run_at, id),
                KEY bot_sources_user_index (bot_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS bot_feed_items (
                source_id BIGINT UNSIGNED NOT NULL,
                item_hash CHAR(64) NOT NULL,
                content_id BIGINT UNSIGNED NULL,
                item_guid VARCHAR(2048) NOT NULL,
                item_published_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (source_id, item_hash),
                KEY bot_feed_items_content_index (content_id),
                KEY bot_feed_items_created_index (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS bot_feed_history (
                bot_user_id INT UNSIGNED NOT NULL,
                feed_hash CHAR(64) NOT NULL,
                item_hash CHAR(64) NOT NULL,
                content_id BIGINT UNSIGNED NULL,
                item_guid VARCHAR(2048) NOT NULL,
                item_published_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (bot_user_id, feed_hash, item_hash),
                KEY bot_feed_history_content_index (content_id),
                KEY bot_feed_history_created_index (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS bot_source_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_id BIGINT UNSIGNED NOT NULL,
                bot_user_id INT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                items_seen INT UNSIGNED NOT NULL DEFAULT 0,
                items_imported INT UNSIGNED NOT NULL DEFAULT 0,
                content_id BIGINT UNSIGNED NULL,
                http_status SMALLINT UNSIGNED NULL,
                error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY bot_source_runs_source_index (source_id, started_at),
                KEY bot_source_runs_bot_index (bot_user_id, started_at),
                KEY bot_source_runs_status_index (status, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        },
        '20260731_localized_email_catalog' => static function (PDO $pdo): void {
            if (tinycat_schema_column_exists($pdo, 'email_templates', 'subject')) {
                $pdo->exec('ALTER TABLE email_templates DROP COLUMN subject');
            }
            if (tinycat_schema_column_exists($pdo, 'email_templates', 'body')) {
                $pdo->exec('ALTER TABLE email_templates DROP COLUMN body');
            }

            $template = $pdo->prepare('INSERT IGNORE INTO email_templates (template_key, enabled) VALUES (?, 1)');
            foreach (email_template_keys() as $key) {
                $template->execute([$key]);
            }
        },
    ];
}

function tinycat_schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$table, $column]);

    return (int) $statement->fetchColumn() > 0;
}

function tinycat_schema_unique_column_index_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0'
    );
    $statement->execute([$table, $column]);

    return (int) $statement->fetchColumn() > 0;
}

if (PHP_SAPI === 'cli') {
    try {
        $result = tinycat_run_updates();
        fwrite(STDOUT, (string) $result['message'] . "\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, "Migration failed: " . $exception->getMessage() . "\n");
        exit(1);
    }
}
