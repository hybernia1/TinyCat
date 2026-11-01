<?php
declare(strict_types=1);

namespace TinyCat\Installation;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Installer
{
    public static function isComplete(): bool
    {
        return (bool) \TinyCat\Support\Config::get('install.complete', false) || \TinyCat\Database\Schema::app_db_ready();
    }

    /** @return array<mixed> */
    public static function &state(): array
    {
        \TinyCat\Http\Session::start();
        if (!isset($_SESSION['tc_install']) || !is_array($_SESSION['tc_install'])) {
            $_SESSION['tc_install'] = [];
        }

        return $_SESSION['tc_install'];
    }

    /** @return array<string, array{code: string, label: string}> */
    public static function languages(): array
    {
        $languages = [];

        foreach (\TinyCat\Support\Languages::language_packages() as $code => $label) {
            $languages[$code] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        ksort($languages);

        return $languages;
    }

    /** @param array<string, array{code: string, label: string}> $languages
     * @param array<mixed> $state */
    public static function locale(array $languages, array $state): string
    {
        $configured = (string) ($state['locale'] ?? \TinyCat\Settings\Store::config('i18n.locale', \TinyCat\Settings\Store::config('install.locale', 'en')));

        if (isset($languages[$configured])) {
            return $configured;
        }

        return array_key_first($languages) ?: 'en';
    }

    /** @param array<string, mixed> $database */
    public static function pdo(array $database, bool $createDatabase = false): PDO
    {
        $host = (string) ($database['host'] ?? 'localhost');
        $name = (string) ($database['name'] ?? '');
        $user = (string) ($database['user'] ?? '');
        $password = (string) ($database['password'] ?? '');
        $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) ($database['charset'] ?? 'utf8mb4'))
            ? (string) $database['charset']
            : 'utf8mb4';
        $port = isset($database['port']) ? ';port=' . (int) $database['port'] : '';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($name === '') {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.required_fields'));
        }

        $dsn = sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            return new PDO($dsn, $user, $password, $options);
        } catch (PDOException $databaseException) {
            if (!$createDatabase || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                throw $databaseException;
            }

            $serverDsn = sprintf('mysql:host=%s%s;charset=%s', $host, $port, $charset);
            $pdo = new PDO($serverDsn, $user, $password, $options);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '` CHARACTER SET ' . $charset);

            return new PDO($dsn, $user, $password, $options);
        }
    }

    public static function create_tables(): void
    {
        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS users (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS content (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                body VARCHAR(2000) NOT NULL,
                author_id INT UNSIGNED NOT NULL,
                published_at DATETIME NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'published',
                PRIMARY KEY (id),
                KEY content_feed_index (published_at, id),
                KEY content_sidebar_index (published_at, author_id, id),
                KEY content_author_index (author_id, published_at, id),
                FULLTEXT KEY content_body_fulltext (body),
                CONSTRAINT fk_content_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS content_images (
                content_id BIGINT UNSIGNED NOT NULL,
                path VARCHAR(190) NOT NULL,
                width INT UNSIGNED NOT NULL,
                height INT UNSIGNED NOT NULL,
                bytes INT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id),
                UNIQUE KEY content_images_path_unique (path),
                CONSTRAINT fk_content_images_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS terms (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY terms_name_unique (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS content_tags (
                content_id BIGINT UNSIGNED NOT NULL,
                term_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id, term_id),
                KEY content_tags_term_index (term_id, content_id),
                CONSTRAINT fk_content_tags_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_tags_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Status\Schema::ensureTagIndex(\TinyCat\Database\Connection::get());

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                normalized_url VARCHAR(2048) NOT NULL,
                url_hash CHAR(64) NOT NULL,
                provider VARCHAR(40) NOT NULL DEFAULT '',
                link_type VARCHAR(20) NOT NULL DEFAULT 'web',
                title VARCHAR(255) NULL,
                description VARCHAR(500) NULL,
                image_url VARCHAR(2048) NULL,
                video_id VARCHAR(80) NULL,
                audio_url VARCHAR(2048) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY links_hash_unique (url_hash),
                FULLTEXT KEY links_search_fulltext (normalized_url, title, description)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS content_links (
                content_id BIGINT UNSIGNED NOT NULL,
                link_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (content_id, link_id),
                KEY content_links_link_index (link_id),
                CONSTRAINT fk_content_links_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_links_link FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS content_likes (
                content_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id, user_id),
                KEY content_likes_user_index (user_id, content_id),
                CONSTRAINT fk_content_likes_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS content_comments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                content_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                user_id INT UNSIGNED NOT NULL,
                body VARCHAR(2000) NOT NULL,
                comments_diff JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY content_comments_content_index (content_id, parent_id, created_at),
                KEY content_comments_user_index (user_id, created_at),
                KEY content_comments_parent_index (parent_id),
                KEY content_comments_recent_index (created_at, id),
                CONSTRAINT fk_content_comments_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_comments_parent FOREIGN KEY (parent_id) REFERENCES content_comments (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS comment_likes (
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (comment_id, user_id),
                KEY comment_likes_user_index (user_id, comment_id),
                CONSTRAINT fk_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES content_comments (id) ON DELETE CASCADE,
                CONSTRAINT fk_comment_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS user_followers (
                user_id INT UNSIGNED NOT NULL,
                follower_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, follower_id),
                KEY user_followers_follower_recent_index (follower_id, created_at DESC, user_id ASC),
                CONSTRAINT fk_user_followers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_followers_follower FOREIGN KEY (follower_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                actor_id INT UNSIGNED NULL,
                content_id BIGINT UNSIGNED NULL,
                comment_id BIGINT UNSIGNED NULL,
                type VARCHAR(40) NOT NULL,
                notification_key VARCHAR(190) NOT NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY notifications_key_unique (user_id, notification_key),
                KEY notifications_user_index (user_id, read_at, created_at),
                KEY notifications_actor_index (actor_id, created_at),
                KEY notifications_content_index (content_id),
                KEY notifications_comment_index (comment_id),
                CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_notifications_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_notifications_comment FOREIGN KEY (comment_id) REFERENCES content_comments (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS content_reports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                content_id BIGINT UNSIGNED NOT NULL,
                reporter_id INT UNSIGNED NULL,
                reason VARCHAR(40) NOT NULL DEFAULT 'other',
                note TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                reviewed_by INT UNSIGNED NULL,
                action_note TEXT NULL,
                PRIMARY KEY (id),
                KEY content_reports_content_reporter_status_index (content_id, reporter_id, status),
                KEY content_reports_status_index (status, created_at),
                KEY content_reports_reporter_index (reporter_id),
                CONSTRAINT fk_content_reports_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_content_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(120) NOT NULL,
                setting_group VARCHAR(60) NOT NULL DEFAULT 'general',
                setting_value LONGTEXT NULL,
                setting_type VARCHAR(20) NOT NULL DEFAULT 'string',
                autoload TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY settings_key_unique (setting_key),
                KEY settings_group_index (setting_group),
                KEY settings_autoload_index (autoload)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS password_reset_tokens (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL,
                version VARCHAR(32) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS ip_action_limits (
                ip_address VARCHAR(45) NOT NULL,
                action_name VARCHAR(40) NOT NULL,
                bucket_start DATETIME NOT NULL,
                action_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (ip_address, action_name, bucket_start),
                KEY ip_action_limits_bucket_index (bucket_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $statements = [
            "CREATE TABLE IF NOT EXISTS bot_sources (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                bot_user_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                feed_url VARCHAR(2048) NOT NULL,
                feed_hash CHAR(64) NOT NULL,
                interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
                publication_interval_minutes INT UNSIGNED NOT NULL DEFAULT 30,
                post_template VARCHAR(2000) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_checked_at DATETIME NULL,
                last_imported_at DATETIME NULL,
                next_run_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY bot_sources_feed_hash_unique (feed_hash),
                KEY bot_sources_due_index (enabled, next_run_at, id),
                KEY bot_sources_user_index (bot_user_id, id),
                CONSTRAINT fk_bot_sources_user FOREIGN KEY (bot_user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS bot_source_posts (
                content_id BIGINT UNSIGNED NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id),
                KEY bot_source_posts_source_index (source_id, content_id),
                CONSTRAINT fk_bot_source_posts_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_bot_source_posts_source FOREIGN KEY (source_id) REFERENCES bot_sources (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS bot_feed_history (
                bot_user_id INT UNSIGNED NOT NULL,
                feed_hash CHAR(64) NOT NULL,
                item_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (bot_user_id, feed_hash, item_hash),
                KEY bot_feed_history_created_index (created_at),
                CONSTRAINT fk_bot_feed_history_user FOREIGN KEY (bot_user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS bot_source_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_id BIGINT UNSIGNED NOT NULL,
                scheduler_run_id BIGINT NULL,
                status VARCHAR(20) NOT NULL,
                started_at DATETIME NOT NULL,
                items_seen INT UNSIGNED NOT NULL DEFAULT 0,
                error VARCHAR(500) NULL,
                PRIMARY KEY (id),
                KEY bot_source_runs_scheduler_index (scheduler_run_id, id),
                KEY bot_source_runs_source_index (source_id, started_at),
                KEY bot_source_runs_status_index (status, started_at),
                KEY bot_source_runs_started_index (started_at, id),
                CONSTRAINT fk_bot_source_runs_source FOREIGN KEY (source_id) REFERENCES bot_sources (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $statement) {
            \TinyCat\Database\Connection::exec($statement);
        }
        \TinyCat\Database\Connection::exec(
            "CREATE TABLE IF NOT EXISTS scheduler_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task VARCHAR(40) NOT NULL, mode VARCHAR(16) NOT NULL,
                actor_id BIGINT NULL, status VARCHAR(24) NOT NULL,
                started_at DATETIME NOT NULL, finished_at DATETIME NULL,
                duration_ms BIGINT NULL, options_json TEXT NOT NULL,
                summary_json TEXT NULL, error TEXT NULL,
                KEY scheduler_runs_task_id (task, id),
                KEY scheduler_runs_started (started_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

    }

    /** @param array<mixed> $state */
    public static function default_settings(array $state): void
    {
        $locale = (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale());
        $defaults = [
            ['site.name', (string) \TinyCat\Settings\Store::config('site.name', 'TinyCat'), 'string', 'site'],
            ['site.home_title', (string) \TinyCat\Settings\Store::config('site.home_title', ''), 'string', 'site'],
            ['site.meta_description', (string) \TinyCat\Settings\Store::config('site.meta_description', ''), 'string', 'site'],
            ['site.logo_url', (string) \TinyCat\Settings\Store::config('site.logo_url', ''), 'string', 'site'],
            ['site.logo_path', (string) \TinyCat\Settings\Store::config('site.logo_path', ''), 'string', 'site'],
            ['site.favicon_url', (string) \TinyCat\Settings\Store::config('site.favicon_url', ''), 'string', 'site'],
            ['site.favicon_path', (string) \TinyCat\Settings\Store::config('site.favicon_path', ''), 'string', 'site'],
            ['site.footer_html', (string) \TinyCat\Settings\Store::config('site.footer_html', ''), 'string', 'site'],
            ['i18n.locale', $locale, 'string', 'localization'],
            ['datetime.timezone', (string) \TinyCat\Settings\Store::config('datetime.timezone', 'Europe/Prague'), 'string', 'localization'],
            ['datetime.date', (string) \TinyCat\Settings\Store::config('datetime.date', 'd.m.Y'), 'string', 'localization'],
            ['datetime.time', (string) \TinyCat\Settings\Store::config('datetime.time', 'H:i'), 'string', 'localization'],
            ['datetime.datetime', (string) \TinyCat\Settings\Store::config('datetime.datetime', 'd.m.Y H:i'), 'string', 'localization'],
            ['cron.token', bin2hex(random_bytes(32)), 'string', 'cron'],
            ['datetime.relative', (bool) \TinyCat\Settings\Store::config('datetime.relative', false), 'bool', 'localization'],
            ['security.captcha.enabled', (bool) \TinyCat\Settings\Store::config('security.captcha.enabled', false), 'bool', 'security'],
            ['security.captcha.provider', (string) \TinyCat\Settings\Store::config('security.captcha.provider', 'recaptcha'), 'string', 'security'],
            ['security.captcha.site_key', (string) \TinyCat\Settings\Store::config('security.captcha.site_key', ''), 'string', 'security'],
            ['security.captcha.secret_key', (string) \TinyCat\Settings\Store::config('security.captcha.secret_key', ''), 'string', 'security'],
            ['security.captcha.login_attempts', 3, 'int', 'security'],
            ['auth.registration.enabled', (bool) \TinyCat\Settings\Store::config('auth.registration.enabled', false), 'bool', 'security'],
            ['auth.registration.auto_approve', (bool) \TinyCat\Settings\Store::config('auth.registration.auto_approve', false), 'bool', 'security'],
            ['performance.minify_css', (bool) \TinyCat\Settings\Store::config('performance.minify_css', false), 'bool', 'performance'],
            ['performance.minify_js', (bool) \TinyCat\Settings\Store::config('performance.minify_js', false), 'bool', 'performance'],
            ['performance.minify_html', (bool) \TinyCat\Settings\Store::config('performance.minify_html', false), 'bool', 'performance'],
            ['content_images.enabled', (bool) \TinyCat\Settings\Store::config('content_images.enabled', true), 'bool', 'content_images'],
            ['content_images.max_upload_kb', (int) \TinyCat\Settings\Store::config('content_images.max_upload_kb', 100), 'int', 'content_images'],
            ['moderation.blocked_urls', (string) \TinyCat\Settings\Store::config('moderation.blocked_urls', ''), 'string', 'moderation'],
            ['email.smtp', \TinyCat\Mail\Smtp::email_smtp_default_config(), 'json', 'email'],
            ['email.templates', \TinyCat\Mail\Templates::email_template_default_states(), 'json', 'email'],
            ['analytics.google_measurement_id', '', 'string', 'analytics'],
        ];

        foreach ($defaults as [$key, $value, $type, $group]) {
            \TinyCat\Settings\Store::setSetting((string) $key, $value, (string) $type, (string) $group);
        }
    }

    public static function create_admin_account(string $username, string $password, string $locale): int
    {
        $username = \TinyCat\Users\Usernames::username_normalize($username);
        $existing = \TinyCat\Database\Connection::one('SELECT id FROM users WHERE username = ?', [$username]);
        $data = [
            'username' => $username,
            'password' => \TinyCat\Users\Authentication::authPassword($password),
            'role' => 'admin',
            'status' => 'active',
            'locale' => $locale,
            'theme' => 'system',
        ];

        if ($existing !== null) {
            \TinyCat\Database\Connection::update('users', $data, ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        return (int) \TinyCat\Database\Connection::insert('users', $data);
    }

    /** @param array<mixed> $state */
    public static function write_config(array $state): void
    {
        $locale = (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale());
        $database = $state['database'] ?? [];

        if (!is_array($database) || $database === []) {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.missing_db'));
        }

        unset($database['driver']);

        $config = [
            'cache' => [
                'driver' => 'filesystem',
            ],
            'database' => [
                'host' => (string) ($database['host'] ?? 'localhost'),
                'name' => (string) ($database['name'] ?? ''),
                'user' => (string) ($database['user'] ?? 'root'),
                'password' => (string) ($database['password'] ?? ''),
                'charset' => (string) ($database['charset'] ?? 'utf8mb4'),
            ],
            'install' => [
                'locale' => $locale,
                'complete' => true,
            ],
        ];

        if (isset($database['port'])) {
            $config['database']['port'] = (int) $database['port'];
        }

        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.php';
        $content = self::config_content($config);

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.config_write_failed'));
        }
    }

    /** @param array<mixed> $config */
    public static function config_content(array $config): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "return " . var_export($config, true) . ";\n";
    }

    /** @return array<string, string> */
    public static function schema_tables(): array
    {
        $tables = \TinyCat\Database\Schema::app_required_tables();

        return array_combine(
            $tables,
            array_map(static fn (string $table): string => 'install.purpose_' . $table, $tables)
        );
    }

    public static function table_exists(string $table): bool
    {
        return (int) \TinyCat\Database\Connection::value(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    /** @return list<string> */
    public static function missing_tables(): array
    {
        return array_values(array_filter(
            array_keys(self::schema_tables()),
            static fn (string $table): bool => !self::table_exists($table)
        ));
    }
}
