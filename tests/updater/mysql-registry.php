<?php
declare(strict_types=1);

use TinyCat\Update\MigrationRegistry;

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

$config = (array) config('database', []);

if (($config['driver'] ?? 'mysql') !== 'mysql') {
    echo "SKIP MySQL migration registry: a local MySQL database is required.\n";
    exit(0);
}

$host = (string) ($config['host'] ?? 'localhost');
$port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
$charset = (string) ($config['charset'] ?? 'utf8mb4');
$databaseName = 'tinycat_registry_test_' . strtolower(bin2hex(random_bytes(5)));
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

if (preg_match('/^tinycat_registry_test_[a-f0-9]{10}$/', $databaseName) !== 1) {
    throw new RuntimeException('Unsafe temporary database name.');
}

$server = new PDO(
    sprintf('mysql:host=%s%s;charset=%s', $host, $port, $charset),
    (string) ($config['user'] ?? ''),
    (string) ($config['password'] ?? ''),
    $options
);
$created = false;

try {
    try {
        $server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    } catch (PDOException $exception) {
        echo 'SKIP MySQL migration registry: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );
    Core::setDb($database);
    $database->exec('CREATE TABLE user_profile_links (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $removeProfileLinks = require dirname(__DIR__, 2) . '/migrations/20260809_001_remove_user_profile_links.php';

    if (!is_callable($removeProfileLinks)) {
        throw new RuntimeException('The profile links removal migration is not callable.');
    }

    $removeProfileLinks($database);
    if ((int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profile_links'")->fetchColumn() !== 0) {
        throw new RuntimeException('The profile links removal migration did not drop its table.');
    }
    $database->exec('CREATE TABLE content (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, created_at DATETIME NOT NULL) ENGINE=InnoDB');
    $removeContentCreatedAt = require dirname(__DIR__, 2) . '/migrations/20260809_002_remove_content_created_at.php';

    if (!is_callable($removeContentCreatedAt)) {
        throw new RuntimeException('The content timestamp removal migration is not callable.');
    }

    $removeContentCreatedAt($database);
    if ((int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content' AND COLUMN_NAME = 'created_at'")->fetchColumn() !== 0) {
        throw new RuntimeException('The content timestamp removal migration did not drop its column.');
    }
    $database->exec('CREATE TABLE links (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, embed_url VARCHAR(2048) NULL) ENGINE=InnoDB');
    $removeLinkEmbedUrl = require dirname(__DIR__, 2) . '/migrations/20260809_003_remove_link_embed_url.php';

    if (!is_callable($removeLinkEmbedUrl)) {
        throw new RuntimeException('The link embed URL removal migration is not callable.');
    }

    $removeLinkEmbedUrl($database);
    if ((int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'links' AND COLUMN_NAME = 'embed_url'")->fetchColumn() !== 0) {
        throw new RuntimeException('The link embed URL removal migration did not drop its column.');
    }
    $database->exec(
        'CREATE TABLE settings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(120) NOT NULL,
            setting_group VARCHAR(60) NOT NULL,
            setting_value LONGTEXT NULL,
            setting_type VARCHAR(20) NOT NULL,
            autoload TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY settings_key_unique (setting_key)
        ) ENGINE=InnoDB'
    );
    $database->exec(
        'CREATE TABLE email_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(80) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY email_templates_key_unique (template_key)
        ) ENGINE=InnoDB'
    );
    $database->exec("INSERT INTO email_templates (template_key, enabled) VALUES ('welcome', 0)");
    $moveEmailTemplateStates = require dirname(__DIR__, 2) . '/migrations/20260809_004_move_email_template_states_to_settings.php';

    if (!is_callable($moveEmailTemplateStates)) {
        throw new RuntimeException('The email template settings migration is not callable.');
    }

    $moveEmailTemplateStates($database);
    $emailTemplateStates = json_decode((string) $database->query("SELECT setting_value FROM settings WHERE setting_key = 'email.templates'")->fetchColumn(), true);
    if (!is_array($emailTemplateStates) || ($emailTemplateStates['welcome'] ?? true) !== false) {
        throw new RuntimeException('The email template settings migration did not preserve disabled states.');
    }
    if ((int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates'")->fetchColumn() !== 0) {
        throw new RuntimeException('The email template settings migration did not drop its table.');
    }
    $legacySmtp = $database->prepare(
        'INSERT INTO settings (setting_key, setting_group, setting_value, setting_type, autoload) VALUES (?, ?, ?, ?, 1)'
    );
    foreach ([
        ['email.smtp.host', 'smtp.registry.test'],
        ['email.smtp.port', '2525'],
        ['email.smtp.username', 'registry-user'],
        ['email.smtp.password', 'registry-password'],
        ['email.smtp.encryption', 'ssl'],
        ['email.from_address', 'registry@example.test'],
        ['email.from_name', 'Registry sender'],
        ['email.welcome_message', 'unused'],
    ] as [$key, $value]) {
        $legacySmtp->execute([$key, 'email', $value, 'string']);
    }
    $moveSmtpSettings = require dirname(__DIR__, 2) . '/migrations/20260809_005_move_smtp_settings_to_json.php';

    if (!is_callable($moveSmtpSettings)) {
        throw new RuntimeException('The SMTP settings migration is not callable.');
    }

    $moveSmtpSettings($database);
    $smtp = json_decode((string) $database->query("SELECT setting_value FROM settings WHERE setting_key = 'email.smtp'")->fetchColumn(), true);
    if (!is_array($smtp)
        || ($smtp['host'] ?? null) !== 'smtp.registry.test'
        || ($smtp['port'] ?? null) !== 2525
        || ($smtp['password'] ?? null) !== 'registry-password'
    ) {
        throw new RuntimeException('The SMTP settings migration did not preserve its configuration.');
    }
    if ((int) $database->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'email.smtp.%' OR setting_key IN ('email.from_address', 'email.from_name', 'email.welcome_message')")->fetchColumn() !== 0) {
        throw new RuntimeException('The SMTP settings migration did not remove legacy email settings.');
    }
    $database->exec(
        'CREATE TABLE user_followers (
            user_id INT UNSIGNED NOT NULL,
            follower_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, follower_id),
            KEY user_followers_follower_index (follower_id, user_id)
        ) ENGINE=InnoDB'
    );
    $optimizeFollowerIndex = require dirname(__DIR__, 2) . '/migrations/20260809_006_optimize_user_followers_recent_index.php';

    if (!is_callable($optimizeFollowerIndex)) {
        throw new RuntimeException('The follower index migration is not callable.');
    }

    $optimizeFollowerIndex($database);
    $newFollowerIndex = (int) $database->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_followers' AND INDEX_NAME = 'user_followers_follower_recent_index'"
    )->fetchColumn();
    $oldFollowerIndex = (int) $database->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_followers' AND INDEX_NAME = 'user_followers_follower_index'"
    )->fetchColumn();

    if ($newFollowerIndex !== 3 || $oldFollowerIndex !== 0) {
        throw new RuntimeException('The follower index migration did not replace the obsolete index.');
    }
    $optimizeFollowerIndex($database);
    $database->exec(
        'CREATE TABLE users (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            last_login_at DATETIME NULL,
            last_seen_at DATETIME NULL
        ) ENGINE=InnoDB'
    );
    $removeUserActivityTimestamps = require dirname(__DIR__, 2) . '/migrations/20260810_002_remove_user_activity_timestamps.php';

    if (!is_callable($removeUserActivityTimestamps)) {
        throw new RuntimeException('The user activity timestamp removal migration is not callable.');
    }

    $removeUserActivityTimestamps($database);
    if ((int) $database->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('last_login_at', 'last_seen_at')"
    )->fetchColumn() !== 0) {
        throw new RuntimeException('The user activity timestamp removal migration did not drop both columns.');
    }
    $removeUserActivityTimestamps($database);
    run(
        'CREATE TABLE schema_migrations (
            version VARCHAR(80) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    insert('schema_migrations', ['version' => '20260731_email_analytics_ip_limits']);
    insert('schema_migrations', ['version' => '20260801_bot_sources']);

    try {
        MigrationRegistry::ensure();
    } catch (RuntimeException $exception) {
        if (str_contains($exception->getMessage(), 'Update TinyCat to 1.0.14')) {
            echo "PASS MySQL outdated migration registry rejected by 2.x baseline.\n";
            return;
        }
        throw $exception;
    }
    throw new RuntimeException('The outdated MySQL migration registry was accepted.');
} finally {
    if ($created && preg_match('/^tinycat_registry_test_[a-f0-9]{10}$/', $databaseName) === 1) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
