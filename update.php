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

    $version = '20260731_email_analytics_ip_limits';
    $applied = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = " . $pdo->quote($version))->fetchColumn() > 0;
    if ($applied) {
        return ['version' => $version, 'applied' => false, 'message' => 'Already up to date.'];
    }

    $pdo->beginTransaction();
    try {
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
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
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

        $templates = [
            ['welcome', 'Welcome to {{site}}', '{{welcome_message}}'],
            ['password_reset', 'Password reset for {{site}}', "Hello {{username}},\n\nReset your password here:\n{{reset_url}}\n\nThis link expires in 60 minutes."],
            ['notification_content_like', '{{actor}} liked your post', '{{actor}} liked your post.\n{{content_url}}'],
            ['notification_content_comment', '{{actor}} commented on your post', '{{actor}} commented on your post.\n{{content_url}}'],
            ['notification_comment_like', '{{actor}} liked your comment', '{{actor}} liked your comment.'],
            ['notification_follow', '{{actor}} followed you', '{{actor}} followed you.\n{{author_url}}'],
            ['notification_content_mention', '{{actor}} mentioned you', '{{actor}} mentioned you in a post.\n{{content_url}}'],
            ['notification_comment_mention', '{{actor}} mentioned you', '{{actor}} mentioned you in a comment.\n{{content_url}}'],
            ['notification_report_resolved', 'Your report was resolved', 'Your report about {{content_url}} was resolved.'],
            ['notification_report_dismissed', 'Your report was dismissed', 'Your report about {{content_url}} was dismissed.'],
        ];
        $statement = $pdo->prepare('INSERT IGNORE INTO email_templates (template_key, subject, body, enabled) VALUES (?, ?, ?, 1)');
        foreach ($templates as $template) {
            $statement->execute($template);
        }

        $settings = [
            ['email.smtp.host', '', 'string', 'email'],
            ['email.smtp.port', '587', 'int', 'email'],
            ['email.smtp.username', '', 'string', 'email'],
            ['email.smtp.password', '', 'string', 'email'],
            ['email.smtp.encryption', 'tls', 'string', 'email'],
            ['email.from_address', '', 'string', 'email'],
            ['email.from_name', 'TinyCat', 'string', 'email'],
            ['email.welcome_message', 'Welcome to {{site}}! Your account {{username}} was created.', 'string', 'email'],
            ['analytics.google_measurement_id', '', 'string', 'analytics'],
        ];
        $setting = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, setting_group) VALUES (?, ?, ?, ?)');
        foreach ($settings as $item) {
            $setting->execute($item);
        }

        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        $pdo->commit();
        return ['version' => $version, 'applied' => true, 'message' => 'Migration applied.'];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
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
