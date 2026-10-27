<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Security\Csrf::token();

$languages = tc_install_languages();
$state = &tc_install_state();
$locale = tc_install_locale($languages, $state);
\TinyCat\Support\Localization::locale($locale);

if (empty($state['database']) && is_array(\TinyCat\Settings\Store::config('database', []))) {
    $state['database'] = \TinyCat\Settings\Store::config('database', []);
}

if (\TinyCat\Http\Request::current()->isPost()) {
    tc_install_handle_post($languages);
}

$state = tc_install_state();
$step = tc_install_step($state);

tc_install_render($step, $languages, $state);

function &tc_install_state(): array
{
    if (!isset($_SESSION['tc_install']) || !is_array($_SESSION['tc_install'])) {
        $_SESSION['tc_install'] = [];
    }

    return $_SESSION['tc_install'];
}

function tc_install_languages(): array
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

function tc_install_locale(array $languages, array $state): string
{
    $configured = (string) ($state['locale'] ?? \TinyCat\Settings\Store::config('i18n.locale', \TinyCat\Settings\Store::config('install.locale', 'en')));

    if (isset($languages[$configured])) {
        return $configured;
    }

    return array_key_first($languages) ?: 'en';
}

function tc_install_step(array $state): string
{
    $status = \TinyCat\Database\Schema::app_db_status();

    if ($status['ready']) {
        return 'done';
    }

    $defaultStep = isset($state['locale']) ? 'db' : 'language';

    if (isset($state['locale']) && $status['connected']) {
        $defaultStep = 'tables';

        if ($status['connected'] && $status['missing_tables'] === [] && !$status['account_ready']) {
            $defaultStep = 'admin';
        }
    }

    $step = (string) \TinyCat\Http\Web::get('step', $defaultStep);
    $allowed = ['language', 'db', 'tables', 'admin', 'done'];

    if (!in_array($step, $allowed, true)) {
        $step = 'language';
    }

    if (in_array($step, ['tables', 'admin'], true) && empty($state['database'])) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.missing_db'));
        \TinyCat\Http\Web::redirect('/install?step=db');
    }

    $tablesReady = $status['connected'] && $status['missing_tables'] === [];

    if ($step === 'admin' && empty($state['tables']) && !$tablesReady) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.missing_tables'));
        \TinyCat\Http\Web::redirect('/install?step=tables');
    }

    if ($step === 'done' && !$status['ready']) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.db_not_ready'));
        \TinyCat\Http\Web::redirect('/install?step=' . $defaultStep);
    }

    return $step;
}

function tc_install_handle_post(array $languages): void
{
    \TinyCat\Security\Csrf::requireToken();

    $step = (string) \TinyCat\Http\Web::post('_install_step', \TinyCat\Http\Web::get('step', 'language'));

    match ($step) {
        'language' => tc_install_store_language($languages),
        'db' => tc_install_store_database(),
        'tables' => tc_install_store_tables(),
        'admin' => tc_install_store_admin(),
        default => \TinyCat\Http\Web::redirect('/install'),
    };
}

function tc_install_store_language(array $languages): never
{
    $selected = (string) \TinyCat\Http\Web::post('locale', '');

    if (!isset($languages[$selected])) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.invalid_language'));
        \TinyCat\Http\Web::redirect('/install?step=language');
    }

    $state = &tc_install_state();
    $state['locale'] = $selected;
    \TinyCat\Support\Localization::locale($selected);

    \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('install.messages.language_saved'));
    \TinyCat\Http\Web::redirect('/install?step=db');
}

function tc_install_store_database(): never
{
    $database = tc_install_database_from_post();

    if (!preg_match('/^[A-Za-z0-9_]+$/', $database['name'])) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.invalid_database'));
        \TinyCat\Http\Web::redirect('/install?step=db');
    }

    try {
        $pdo = tc_install_pdo($database, true);
        $pdo->query('SELECT 1');

        $state = &tc_install_state();
        $state['database'] = $database;
        unset($state['tables']);

        \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('install.messages.db_connected'));
        \TinyCat\Http\Web::redirect('/install?step=tables');
    } catch (Throwable $exception) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.db_failed', ['message' => $exception->getMessage()]));
        \TinyCat\Http\Web::redirect('/install?step=db');
    }
}

function tc_install_store_tables(): never
{
    $state = &tc_install_state();

    if (empty($state['database']) || !is_array($state['database'])) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.missing_db'));
        \TinyCat\Http\Web::redirect('/install?step=db');
    }

    try {
        \TinyCat\Database\Connection::set(tc_install_pdo($state['database']));
        tc_install_create_tables();

        $missingTables = tc_install_missing_tables();

        if ($missingTables !== []) {
            throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.missing_tables') . ' ' . implode(', ', $missingTables));
        }

        $state['tables'] = true;
        \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('install.messages.tables_created'));
        \TinyCat\Http\Web::redirect('/install?step=admin');
    } catch (Throwable $exception) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.db_failed', ['message' => $exception->getMessage()]));
        \TinyCat\Http\Web::redirect('/install?step=tables');
    }
}

function tc_install_store_admin(): never
{
    $state = &tc_install_state();

    if (empty($state['database']) || !is_array($state['database'])) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.missing_db'));
        \TinyCat\Http\Web::redirect('/install?step=db');
    }

    try {
        \TinyCat\Database\Connection::set(tc_install_pdo($state['database']));
    } catch (Throwable $exception) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.db_failed', ['message' => $exception->getMessage()]));
        \TinyCat\Http\Web::redirect('/install?step=db');
    }

    $missingTables = tc_install_missing_tables();

    if ($missingTables !== []) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.missing_tables'));
        \TinyCat\Http\Web::redirect('/install?step=tables');
    }

    $username = \TinyCat\Users\Usernames::username_normalize((string) \TinyCat\Http\Web::post('username', ''));
    $password = (string) \TinyCat\Http\Web::post('password', '');
    $confirm = (string) \TinyCat\Http\Web::post('password_confirm', '');

    if ($username === '' || $password === '') {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.required_fields'));
        \TinyCat\Http\Web::redirect('/install?step=admin');
    }

    if (!\TinyCat\Users\Usernames::username_valid($username)) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.invalid_username'));
        \TinyCat\Http\Web::redirect('/install?step=admin');
    }

    if (strlen($password) < 8) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.password_length'));
        \TinyCat\Http\Web::redirect('/install?step=admin');
    }

    if (\TinyCat\Users\Login::auth_password_too_long($password)) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('account.messages.password_too_long'));
        \TinyCat\Http\Web::redirect('/install?step=admin');
    }

    if ($password !== $confirm) {
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('install.messages.password_mismatch'));
        \TinyCat\Http\Web::redirect('/install?step=admin');
    }

    try {
        $adminId = tc_install_create_admin_account($username, $password, (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale()));
        tc_install_default_settings($state);
        tc_install_write_config($state);
        unset($_SESSION['tc_install']);
        \TinyCat\Users\Authentication::authLogin($adminId);

        \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('install.messages.admin_created'));
        \TinyCat\Http\Web::redirect('/install?step=done');
    } catch (Throwable $exception) {
        \TinyCat\Http\Session::flash('error', $exception->getMessage());
        \TinyCat\Http\Web::redirect('/install?step=admin');
    }
}

function tc_install_database_from_post(): array
{
    $database = [
        'driver' => 'mysql',
        'host' => trim((string) \TinyCat\Http\Web::post('host', 'localhost')),
        'name' => trim((string) \TinyCat\Http\Web::post('name', '')),
        'user' => trim((string) \TinyCat\Http\Web::post('user', 'root')),
        'password' => (string) \TinyCat\Http\Web::post('password', ''),
        'charset' => trim((string) \TinyCat\Http\Web::post('charset', 'utf8mb4')) ?: 'utf8mb4',
    ];

    $port = (int) \TinyCat\Http\Web::post('port', 0);

    if ($port > 0) {
        $database['port'] = $port;
    }

    return $database;
}

function tc_install_pdo(array $database, bool $createDatabase = false): PDO
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

function tc_install_create_tables(): void
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
            edit_locked_at DATETIME NULL,
            edit_locked_by INT UNSIGNED NULL,
            edit_lock_reason VARCHAR(80) NULL,
            PRIMARY KEY (id),
            KEY content_feed_index (published_at, id),
            KEY content_sidebar_index (published_at, author_id, id),
            KEY content_author_index (author_id, published_at, id),
            FULLTEXT KEY content_body_fulltext (body),
            CONSTRAINT fk_content_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_content_edit_locked_by FOREIGN KEY (edit_locked_by) REFERENCES users (id) ON DELETE SET NULL
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

    \TinyCat\Database\Connection::exec(
        "CREATE TABLE IF NOT EXISTS links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            normalized_url VARCHAR(2048) NOT NULL,
            url_hash CHAR(64) NOT NULL,
            provider VARCHAR(40) NOT NULL DEFAULT 'web',
            link_type VARCHAR(20) NOT NULL DEFAULT 'link',
            title VARCHAR(255) NULL,
            description VARCHAR(500) NULL,
            image_url VARCHAR(2048) NULL,
            video_id VARCHAR(80) NULL,
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
            UNIQUE KEY content_reports_unique (content_id, reporter_id),
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

function tc_install_default_settings(array $state): void
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

function tc_install_create_admin_account(string $username, string $password, string $locale): int
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

function tc_install_write_config(array $state): void
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
    $content = tc_install_config_content($config);

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException(\TinyCat\Support\Localization::t('install.messages.config_write_failed'));
    }
}

function tc_install_config_content(array $config): string
{
    return "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return " . var_export($config, true) . ";\n";
}

function tc_install_schema_tables(): array
{
    $tables = \TinyCat\Database\Schema::app_required_tables();

    return array_combine(
        $tables,
        array_map(static fn (string $table): string => 'install.purpose_' . $table, $tables)
    );
}

function tc_install_table_exists(string $table): bool
{
    return (int) \TinyCat\Database\Connection::value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    ) > 0;
}

function tc_install_missing_tables(): array
{
    return array_values(array_filter(
        array_keys(tc_install_schema_tables()),
        static fn (string $table): bool => !tc_install_table_exists($table)
    ));
}

function tc_install_render(string $step, array $languages, array $state): void
{
    $appName = 'TinyCat';
    $pageTitle = \TinyCat\Support\Localization::t('install.title') . ' | ' . $appName;
    $error = \TinyCat\Http\Session::flash('error');
    $success = \TinyCat\Http\Session::flash('success');
    ?>
    <!doctype html>
    <html lang="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::locale()) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="<?= \TinyCat\Support\Html::e(\TinyCat\Security\Csrf::token()) ?>">
        <title><?= \TinyCat\Support\Html::e($pageTitle) ?></title>
        <link rel="stylesheet" href="<?= \TinyCat\Support\Html::e(\TinyCat\Support\ClientAssets::styles('/install')[0]) ?>">
        <?php foreach (\TinyCat\Support\ClientAssets::scripts('/install') as $scriptUrl): ?>
            <script src="<?= \TinyCat\Support\Html::e($scriptUrl) ?>" defer></script>
        <?php endforeach; ?>
    </head>
    <body data-icon-sprite="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Assets::asset('icons.svg')) ?>">
        <header class="navbar">
            <div class="container navbar-inner">
                <strong><?= \TinyCat\Support\Html::e($appName) ?></strong>
                <span class="badge badge-primary"><?= \TinyCat\Support\Html::icon('settings') ?> <?= \TinyCat\Support\Html::et('install.title') ?></span>
            </div>
        </header>

        <main class="section">
            <div class="container-sm stack stack-gap-24">
                <div class="stack stack-gap-8">
                    <h1 class="text-2xl m-0"><?= \TinyCat\Support\Html::et('install.title') ?></h1>
                </div>

                <?= \TinyCat\Support\View::part('Site/install/steps', ['current' => $step]) ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= \TinyCat\Support\Html::e($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= \TinyCat\Support\Html::e($success) ?></div>
                <?php endif; ?>

                <?php
                match ($step) {
                    'language' => tc_install_language_view($languages, $state),
                    'db' => tc_install_database_view($state),
                    'tables' => tc_install_tables_view($state),
                    'admin' => tc_install_admin_view(),
                    'done' => tc_install_done_view(),
                    default => tc_install_language_view($languages, $state),
                };
                ?>
            </div>
        </main>
    </body>
    </html>
    <?php
}

function tc_install_language_view(array $languages, array $state): void
{
    $selected = (string) ($state['locale'] ?? \TinyCat\Support\Localization::locale());
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('globe') ?> <?= \TinyCat\Support\Html::et('install.language_title') ?></h2>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/install?step=language">
                <?= \TinyCat\Security\Csrf::field() ?>
                <input type="hidden" name="_install_step" value="language">
                <div class="choice-list">
                    <?php foreach ($languages as $language): ?>
                        <?php $checked = $language['code'] === $selected; ?>
                        <label class="choice-card">
                            <input type="radio" name="locale" value="<?= \TinyCat\Support\Html::e($language['code']) ?>"<?= $checked ? ' checked' : '' ?>>
                            <span>
                                <strong><?= \TinyCat\Support\Html::e($language['label']) ?></strong>
                                <small><?= \TinyCat\Support\Html::e(strtoupper((string) $language['code'])) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="choice-row">
                    <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('check') ?> <span><?= \TinyCat\Support\Html::et('common.continue') ?></span></button>
                </div>
            </form>
        </div>
    </article>
    <?php
}

function tc_install_database_view(array $state): void
{
    $database = is_array($state['database'] ?? null) ? $state['database'] : (array) \TinyCat\Settings\Store::config('database', []);
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('database') ?> <?= \TinyCat\Support\Html::et('install.db_title') ?></h2>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/install?step=db">
                <?= \TinyCat\Security\Csrf::field() ?>
                <input type="hidden" name="_install_step" value="db">
                <div class="grid sm:grid-2">
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('install.db_host') ?></span>
                        <input class="input" name="host" value="<?= \TinyCat\Support\Html::e($database['host'] ?? 'localhost') ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('install.db_port') ?></span>
                        <input class="input" type="number" name="port" min="1" max="65535" value="<?= \TinyCat\Support\Html::e($database['port'] ?? '') ?>">
                    </label>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('install.db_name') ?></span>
                        <input class="input" name="name" value="<?= \TinyCat\Support\Html::e($database['name'] ?? '') ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('install.db_user') ?></span>
                        <input class="input" name="user" value="<?= \TinyCat\Support\Html::e($database['user'] ?? 'root') ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('install.db_password') ?></span>
                        <input class="input" type="password" name="password" value="<?= \TinyCat\Support\Html::e($database['password'] ?? '') ?>">
                    </label>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('install.db_charset') ?></span>
                        <input class="input" name="charset" value="<?= \TinyCat\Support\Html::e($database['charset'] ?? 'utf8mb4') ?>" required>
                    </label>
                </div>
                <div class="choice-row">
                    <a class="btn btn-secondary" href="/install?step=language"><?= \TinyCat\Support\Html::icon('arrow-left') ?> <span><?= \TinyCat\Support\Html::et('common.back') ?></span></a>
                    <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('arrow-right') ?> <span><?= \TinyCat\Support\Html::et('install.db_test') ?></span></button>
                </div>
            </form>
        </div>
    </article>
    <?php
}

function tc_install_tables_view(array $state): void
{
    $tableStatuses = [];
    $error = null;

    if (!empty($state['database']) && is_array($state['database'])) {
        try {
            \TinyCat\Database\Connection::set(tc_install_pdo($state['database']));

            foreach (array_keys(tc_install_schema_tables()) as $table) {
                $tableStatuses[$table] = tc_install_table_exists($table);
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('database') ?> <?= \TinyCat\Support\Html::et('install.tables_title') ?></h2>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('install.tables_intro') ?></p>
            <?php if ($error !== null): ?>
                <div class="alert alert-danger"><?= \TinyCat\Support\Html::et('install.messages.db_failed', ['message' => $error]) ?></div>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= \TinyCat\Support\Html::et('install.table_name') ?></th>
                            <th><?= \TinyCat\Support\Html::et('install.table_purpose') ?></th>
                            <th><?= \TinyCat\Support\Html::et('install.table_state') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (tc_install_schema_tables() as $table => $purpose): ?>
                            <?php $exists = (bool) ($tableStatuses[$table] ?? false); ?>
                            <tr>
                                <td><code><?= \TinyCat\Support\Html::e($table) ?></code></td>
                                <td><?= \TinyCat\Support\Html::et($purpose) ?></td>
                                <td>
                                    <span class="badge<?= $exists ? ' badge-primary' : '' ?>">
                                        <?= $exists ? \TinyCat\Support\Html::et('install.state_exists') : \TinyCat\Support\Html::et('install.state_missing') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" action="/install?step=tables" class="choice-row">
                <?= \TinyCat\Security\Csrf::field() ?>
                <input type="hidden" name="_install_step" value="tables">
                <a class="btn btn-secondary" href="/install?step=db"><?= \TinyCat\Support\Html::icon('arrow-left') ?> <span><?= \TinyCat\Support\Html::et('common.back') ?></span></a>
                <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('check') ?> <span><?= \TinyCat\Support\Html::et('install.create_tables') ?></span></button>
            </form>
        </div>
    </article>
    <?php
}

function tc_install_admin_view(): void
{
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('shield') ?> <?= \TinyCat\Support\Html::et('install.admin_title') ?></h2>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/install?step=admin">
                <?= \TinyCat\Security\Csrf::field() ?>
                <input type="hidden" name="_install_step" value="admin">
                <label class="field">
                    <span class="label"><?= \TinyCat\Support\Html::et('common.username') ?></span>
                    <input class="input" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="<?= \TinyCat\Support\Html::e(\TinyCat\Users\Usernames::username_pattern()) ?>" minlength="2" maxlength="32" required>
                    <span class="help"><?= \TinyCat\Support\Html::e(\TinyCat\Users\Usernames::username_hint()) ?></span>
                </label>
                <div class="grid sm:grid-2">
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('common.password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= \TinyCat\Users\Login::auth_password_max_length() ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('install.admin_password_confirm') ?></span>
                        <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= \TinyCat\Users\Login::auth_password_max_length() ?>" required>
                    </label>
                </div>
                <div class="choice-row">
                    <a class="btn btn-secondary" href="/install?step=tables"><?= \TinyCat\Support\Html::icon('arrow-left') ?> <span><?= \TinyCat\Support\Html::et('common.back') ?></span></a>
                    <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('save') ?> <span><?= \TinyCat\Support\Html::et('common.finish') ?></span></button>
                </div>
            </form>
        </div>
    </article>
    <?php
}

function tc_install_done_view(): void
{
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('check-circle') ?> <?= \TinyCat\Support\Html::et('install.done_title') ?></h2>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('install.done_intro') ?></p>
            <ul class="result-list">
                <li class="result-item"><?= \TinyCat\Support\Html::icon('globe') ?> <span><?= \TinyCat\Support\Html::et('common.language') ?>: <strong><?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::locale()) ?></strong></span></li>
                <li class="result-item"><?= \TinyCat\Support\Html::icon('database') ?> <span><?= \TinyCat\Support\Html::et('common.tables') ?>: <strong><?= \TinyCat\Support\Html::e(implode(', ', \TinyCat\Database\Schema::app_required_tables())) ?></strong></span></li>
                <li class="result-item"><?= \TinyCat\Support\Html::icon('shield') ?> <span><?= \TinyCat\Support\Html::et('common.account') ?>: <strong><?= \TinyCat\Support\Html::et('common.done') ?></strong></span></li>
            </ul>
            <div class="btn-group">
                <a class="btn btn-primary" href="/admin"><?= \TinyCat\Support\Html::icon('dashboard') ?> <span><?= \TinyCat\Support\Html::et('install.open_admin') ?></span></a>
                <a class="btn btn-secondary" href="/"><?= \TinyCat\Support\Html::icon('home') ?> <span><?= \TinyCat\Support\Html::et('install.open_index') ?></span></a>
            </div>
        </div>
    </article>
    <?php
}
