<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

csrf_token();

$languages = tc_install_languages();
$state = &tc_install_state();
$locale = tc_install_locale($languages, $state);
locale($locale);

if ((bool) config('install.installed', false) && empty($state['database']) && is_array(config('database', []))) {
    $state['database'] = config('database', []);
}

if (is_post()) {
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
    $directory = (string) config('i18n.directory', dirname(__DIR__, 2) . '/lang');
    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $languages = [];

    foreach ($files as $file) {
        $code = pathinfo($file, PATHINFO_FILENAME);

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
            continue;
        }

        $data = json_decode((string) file_get_contents($file), true);
        $label = is_array($data) ? (string) ($data['install']['language_label'] ?? strtoupper($code)) : strtoupper($code);

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
    $configured = (string) ($state['locale'] ?? config('i18n.locale', config('install.locale', config('app.locale', 'en'))));

    if (isset($languages[$configured])) {
        return $configured;
    }

    return array_key_first($languages) ?: 'en';
}

function tc_install_step(array $state): string
{
    $status = app_db_status();

    if ((bool) config('install.installed', false) && $status['ready']) {
        return 'done';
    }

    $defaultStep = isset($state['locale']) ? 'db' : 'language';

    if ((bool) config('install.installed', false)) {
        $defaultStep = $status['connected'] ? 'tables' : 'db';

        if ($status['connected'] && $status['missing_tables'] === [] && !$status['account_ready']) {
            $defaultStep = 'admin';
        }
    }

    $step = (string) get('step', $defaultStep);
    $allowed = ['language', 'db', 'tables', 'admin', 'done'];

    if (!in_array($step, $allowed, true)) {
        $step = 'language';
    }

    if (in_array($step, ['tables', 'admin'], true) && empty($state['database'])) {
        flash('error', t('install.messages.missing_db'));
        redirect('/install?step=db');
    }

    $tablesReady = $status['connected'] && $status['missing_tables'] === [];

    if ($step === 'admin' && empty($state['tables']) && !$tablesReady) {
        flash('error', t('install.messages.missing_tables'));
        redirect('/install?step=tables');
    }

    if ($step === 'done' && !$status['ready']) {
        flash('error', t('install.messages.db_not_ready'));
        redirect('/install?step=' . $defaultStep);
    }

    return $step;
}

function tc_install_handle_post(array $languages): void
{
    csrf_require();

    $step = (string) post('_install_step', get('step', 'language'));

    match ($step) {
        'language' => tc_install_store_language($languages),
        'db' => tc_install_store_database(),
        'tables' => tc_install_store_tables(),
        'admin' => tc_install_store_admin(),
        default => redirect('/install'),
    };
}

function tc_install_store_language(array $languages): never
{
    $selected = (string) post('locale', '');

    if (!isset($languages[$selected])) {
        flash('error', t('install.messages.invalid_language'));
        redirect('/install?step=language');
    }

    $state = &tc_install_state();
    $state['locale'] = $selected;
    locale($selected);

    flash('success', t('install.messages.language_saved'));
    redirect('/install?step=db');
}

function tc_install_store_database(): never
{
    $database = tc_install_database_from_post();

    if (!preg_match('/^[A-Za-z0-9_]+$/', $database['name'])) {
        flash('error', t('install.messages.invalid_database'));
        redirect('/install?step=db');
    }

    try {
        $pdo = tc_install_pdo($database, true);
        $pdo->query('SELECT 1');

        $state = &tc_install_state();
        $state['database'] = $database;
        unset($state['tables']);

        flash('success', t('install.messages.db_connected'));
        redirect('/install?step=tables');
    } catch (Throwable $exception) {
        flash('error', t('install.messages.db_failed', ['message' => $exception->getMessage()]));
        redirect('/install?step=db');
    }
}

function tc_install_store_tables(): never
{
    $state = &tc_install_state();

    if (empty($state['database']) || !is_array($state['database'])) {
        flash('error', t('install.messages.missing_db'));
        redirect('/install?step=db');
    }

    try {
        Core::setDb(tc_install_pdo($state['database']));
        tc_install_create_tables();

        $missingTables = tc_install_missing_tables();

        if ($missingTables !== []) {
            throw new RuntimeException(t('install.messages.missing_tables') . ' ' . implode(', ', $missingTables));
        }

        $state['tables'] = true;
        flash('success', t('install.messages.tables_created'));
        redirect('/install?step=admin');
    } catch (Throwable $exception) {
        flash('error', t('install.messages.db_failed', ['message' => $exception->getMessage()]));
        redirect('/install?step=tables');
    }
}

function tc_install_store_admin(): never
{
    $state = &tc_install_state();

    if (empty($state['database']) || !is_array($state['database'])) {
        flash('error', t('install.messages.missing_db'));
        redirect('/install?step=db');
    }

    try {
        Core::setDb(tc_install_pdo($state['database']));
    } catch (Throwable $exception) {
        flash('error', t('install.messages.db_failed', ['message' => $exception->getMessage()]));
        redirect('/install?step=db');
    }

    $missingTables = tc_install_missing_tables();

    if ($missingTables !== []) {
        flash('error', t('install.messages.missing_tables'));
        redirect('/install?step=tables');
    }

    $username = username_normalize((string) post('username', ''));
    $password = (string) post('password', '');
    $confirm = (string) post('password_confirm', '');

    if ($username === '' || $password === '') {
        flash('error', t('install.messages.required_fields'));
        redirect('/install?step=admin');
    }

    if (!username_valid($username)) {
        flash('error', t('install.messages.invalid_username'));
        redirect('/install?step=admin');
    }

    if (strlen($password) < 8) {
        flash('error', t('install.messages.password_length'));
        redirect('/install?step=admin');
    }

    if ($password !== $confirm) {
        flash('error', t('install.messages.password_mismatch'));
        redirect('/install?step=admin');
    }

    try {
        $adminId = tc_install_create_admin_account($username, $password, (string) ($state['locale'] ?? locale()));
        tc_install_default_settings($state);
        tc_install_write_config($state);
        unset($_SESSION['tc_install']);
        auth_login($adminId);

        flash('success', t('install.messages.admin_created'));
        redirect('/install?step=done');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('/install?step=admin');
    }
}

function tc_install_database_from_post(): array
{
    $database = [
        'driver' => 'mysql',
        'host' => trim((string) post('host', 'localhost')),
        'name' => trim((string) post('name', '')),
        'user' => trim((string) post('user', 'root')),
        'password' => (string) post('password', ''),
        'charset' => trim((string) post('charset', 'utf8mb4')) ?: 'utf8mb4',
    ];

    $port = (int) post('port', 0);

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
        throw new RuntimeException(t('install.messages.required_fields'));
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
    run(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(32) NOT NULL,
            password VARCHAR(255) NULL,
            recovery_hash VARCHAR(128) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'user',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            locale VARCHAR(12) NULL,
            note TEXT NULL,
            website VARCHAR(255) NULL,
            bio TEXT NULL,
            avatar_path VARCHAR(255) NULL,
            avatar_url VARCHAR(255) NULL,
            muted_until DATETIME NULL,
            muted_by INT UNSIGNED NULL,
            muted_reason VARCHAR(80) NULL,
            last_login_at DATETIME NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY users_username_unique (username),
            UNIQUE KEY users_recovery_hash_unique (recovery_hash),
            KEY users_role_status_index (role, status),
            KEY users_avatar_index (avatar_url),
            KEY users_mute_index (muted_until),
            KEY users_activity_index (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS content (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            body LONGTEXT NULL,
            author_id INT UNSIGNED NULL,
            published_at DATETIME NULL,
            edit_locked_at DATETIME NULL,
            edit_locked_by INT UNSIGNED NULL,
            edit_lock_reason VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY content_status_index (status),
            KEY content_feed_index (status, published_at, id),
            KEY content_sidebar_index (status, published_at, author_id, id),
            KEY content_author_index (author_id, status, published_at, id),
            KEY content_edit_lock_index (edit_locked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS terms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY terms_name_unique (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS content_tags (
            content_id BIGINT UNSIGNED NOT NULL,
            term_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (content_id, term_id),
            KEY content_tags_term_index (term_id, content_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url_hash CHAR(64) NOT NULL,
            url TEXT NOT NULL,
            final_url TEXT NULL,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            image_url TEXT NULL,
            site_name VARCHAR(120) NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'web',
            external_id VARCHAR(120) NULL,
            embed_url TEXT NULL,
            fetched_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY links_url_hash_unique (url_hash),
            KEY links_source_index (source, external_id),
            KEY links_fetched_index (fetched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS content_links (
            content_id BIGINT UNSIGNED NOT NULL,
            link_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (content_id, link_id),
            KEY content_links_link_index (link_id),
            KEY content_links_content_index (content_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS content_shares (
            content_id BIGINT UNSIGNED NOT NULL,
            shared_content_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (content_id),
            KEY content_shares_shared_index (shared_content_id, content_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS content_reactions (
            content_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            reaction VARCHAR(12) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (content_id, user_id),
            KEY content_reactions_user_index (user_id, content_id),
            KEY content_reactions_reaction_index (content_id, reaction)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS content_comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            content_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY content_comments_content_index (content_id, parent_id, created_at),
            KEY content_comments_user_index (user_id, created_at),
            KEY content_comments_parent_index (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS comment_likes (
            comment_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (comment_id, user_id),
            KEY comment_likes_user_index (user_id, comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS user_followers (
            user_id INT UNSIGNED NOT NULL,
            follower_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, follower_id),
            KEY user_followers_follower_index (follower_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            actor_id INT UNSIGNED NOT NULL,
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
            KEY notifications_comment_index (comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS content_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            content_id BIGINT UNSIGNED NOT NULL,
            reporter_id INT UNSIGNED NOT NULL,
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
            KEY content_reports_content_index (content_id),
            KEY content_reports_reporter_index (reporter_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS blocked_domains (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            domain VARCHAR(190) NOT NULL,
            reason TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY blocked_domains_domain_unique (domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS user_action_limits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            action_name VARCHAR(40) NOT NULL,
            bucket_start DATETIME NOT NULL,
            action_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_action_limits_unique (user_id, action_name, bucket_start),
            KEY user_action_limits_user_index (user_id, bucket_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
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
}

function tc_install_default_settings(array $state): void
{
    $locale = (string) ($state['locale'] ?? locale());
    $defaults = [
        ['site.name', (string) config('site.name', config('app.name', 'TinyCat')), 'string', 'site'],
        ['site.logo_url', (string) config('site.logo_url', ''), 'string', 'site'],
        ['site.logo_path', (string) config('site.logo_path', ''), 'string', 'site'],
        ['site.favicon_url', (string) config('site.favicon_url', ''), 'string', 'site'],
        ['site.favicon_path', (string) config('site.favicon_path', ''), 'string', 'site'],
        ['site.footer_html', (string) config('site.footer_html', ''), 'string', 'site'],
        ['i18n.locale', $locale, 'string', 'localization'],
        ['datetime.timezone', (string) config('datetime.timezone', 'Europe/Prague'), 'string', 'localization'],
        ['datetime.date', (string) config('datetime.date', 'd.m.Y'), 'string', 'localization'],
        ['datetime.time', (string) config('datetime.time', 'H:i'), 'string', 'localization'],
        ['datetime.datetime', (string) config('datetime.datetime', 'd.m.Y H:i'), 'string', 'localization'],
        ['datetime.relative', (bool) config('datetime.relative', false), 'bool', 'localization'],
        ['security.captcha.enabled', (bool) config('security.captcha.enabled', true), 'bool', 'security'],
        ['auth.registration.enabled', (bool) config('auth.registration.enabled', false), 'bool', 'security'],
        ['auth.registration.auto_approve', (bool) config('auth.registration.auto_approve', false), 'bool', 'security'],
    ];

    foreach ($defaults as [$key, $value, $type, $group]) {
        setting_set((string) $key, $value, (string) $type, (string) $group);
    }
}

function tc_install_create_admin_account(string $username, string $password, string $locale): int
{
    $username = username_normalize($username);
    $existing = one('SELECT id FROM users WHERE username = ?', [$username]);
    $data = [
        'username' => $username,
        'password' => auth_password($password),
        'recovery_hash' => user_recovery_hash_generate(),
        'role' => 'admin',
        'status' => 'active',
        'locale' => $locale,
        'note' => '',
    ];

    if ($existing !== null) {
        update('users', $data, ['id' => $existing['id']]);
        return (int) $existing['id'];
    }

    return (int) insert('users', $data);
}

function tc_install_write_config(array $state): void
{
    $locale = (string) ($state['locale'] ?? locale());
    $database = $state['database'] ?? [];

    if (!is_array($database) || $database === []) {
        throw new RuntimeException(t('install.messages.missing_db'));
    }

    $config = config();
    $config['database'] = $database;
    $config['app']['debug'] = false;
    $config['auth'] = array_replace_recursive([
        'login_url' => '/login',
        'home_url' => '/admin',
        'account_url' => '/account',
        'remember_days' => 30,
        'online_window' => 300,
        'online_touch_interval' => 60,
        'registration' => [
            'enabled' => false,
            'auto_approve' => false,
        ],
    ], is_array($config['auth'] ?? null) ? $config['auth'] : []);
    $config['install']['installed'] = true;
    $config['install']['installed_at'] = date(DATE_ATOM);
    $config['install']['locale'] = $locale;

    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.php';
    $content = tc_install_config_content($config);

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException(t('install.messages.config_write_failed'));
    }
}

function tc_install_config_content(array $config): string
{
    unset(
        $config['i18n']['directory'],
        $config['assets']['directory'],
        $config['avatar']['directory'],
        $config['site']['image_directory'],
        $config['app']['locale'],
        $config['i18n']['locale'],
        $config['i18n']['fallback'],
        $config['datetime'],
        $config['security']['captcha']['enabled'],
        $config['directory']
    );

    return "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "// App config file. Paths are derived from this file so the project stays portable.\n\n"
        . "\$base = __DIR__;\n"
        . "\$path = static function (string \$path = '') use (\$base): string {\n"
        . "    \$path = trim(str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, \$path), DIRECTORY_SEPARATOR);\n\n"
        . "    return rtrim(\$base, DIRECTORY_SEPARATOR) . (\$path === '' ? '' : DIRECTORY_SEPARATOR . \$path);\n"
        . "};\n\n"
        . "\$config = " . var_export($config, true) . ";\n\n"
        . "\$config['i18n']['directory'] = \$path('lang');\n"
        . "\$config['assets']['directory'] = \$path('assets');\n"
        . "\$config['avatar']['directory'] = \$path('uploads/avatars');\n"
        . "\$config['site']['image_directory'] = \$path('uploads/site');\n"
        . "return \$config;\n";
}

function tc_install_schema_tables(): array
{
    return [
        'users' => 'install.purpose_users',
        'content' => 'install.purpose_content',
        'terms' => 'install.purpose_terms',
        'content_tags' => 'install.purpose_content_tags',
        'links' => 'install.purpose_links',
        'content_links' => 'install.purpose_content_links',
        'content_shares' => 'install.purpose_content_shares',
        'content_reactions' => 'install.purpose_content_reactions',
        'content_comments' => 'install.purpose_content_comments',
        'comment_likes' => 'install.purpose_comment_likes',
        'user_followers' => 'install.purpose_user_followers',
        'notifications' => 'install.purpose_notifications',
        'content_reports' => 'install.purpose_content_reports',
        'blocked_domains' => 'install.purpose_blocked_domains',
        'user_action_limits' => 'install.purpose_user_action_limits',
        'settings' => 'install.purpose_settings',
    ];
}

function tc_install_table_exists(string $table): bool
{
    return (int) val(
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
    $appName = (string) config('app.name', 'TinyCat');
    $pageTitle = t('install.title') . ' | ' . $appName;
    $error = flash('error');
    $success = flash('success');
    ?>
    <!doctype html>
    <html lang="<?= e(locale()) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
        <title><?= e($pageTitle) ?></title>
        <link rel="stylesheet" href="<?= e(asset('css/tinycat.css')) ?>">
        <script src="<?= e(asset('js/tinycat.js')) ?>" defer></script>
    </head>
    <body>
        <header class="navbar">
            <div class="container navbar-inner">
                <strong><?= e($appName) ?></strong>
                <span class="badge badge-primary"><?= icon('settings') ?> <?= et('install.title') ?></span>
            </div>
        </header>

        <main class="section">
            <div class="container-sm stack" style="--stack-gap: 24px;">
                <div class="stack" style="--stack-gap: 8px;">
                    <h1 class="text-2xl m-0"><?= et('install.title') ?></h1>
                </div>

                <?= tc_install_steps_html($step) ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= e($success) ?></div>
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

function tc_install_steps_html(string $current): string
{
    $steps = [
        'language' => 'install.step_language',
        'db' => 'install.step_database',
        'tables' => 'install.step_tables',
        'admin' => 'install.step_admin',
        'done' => 'install.step_done',
    ];
    $currentIndex = array_search($current, array_keys($steps), true);
    $currentIndex = $currentIndex === false ? 0 : $currentIndex;

    ob_start();
    ?>
    <ol class="steps" aria-label="<?= et('install.title') ?>">
        <?php $index = 0; ?>
        <?php foreach ($steps as $step => $label): ?>
            <?php
            $class = 'step';
            $class .= $step === $current ? ' is-active' : '';
            $class .= $index < $currentIndex ? ' is-complete' : '';
            ?>
            <li class="<?= e($class) ?>">
                <span class="step-marker"><?= e($index + 1) ?></span>
                <span><?= et($label) ?></span>
            </li>
            <?php $index++; ?>
        <?php endforeach; ?>
    </ol>
    <?php

    return trim((string) ob_get_clean());
}

function tc_install_language_view(array $languages, array $state): void
{
    $selected = (string) ($state['locale'] ?? locale());
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('globe') ?> <?= et('install.language_title') ?></h2>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/install?step=language">
                <?= csrf_field() ?>
                <input type="hidden" name="_install_step" value="language">
                <div class="choice-list">
                    <?php foreach ($languages as $language): ?>
                        <?php $checked = $language['code'] === $selected; ?>
                        <label class="choice-card">
                            <input type="radio" name="locale" value="<?= e($language['code']) ?>"<?= $checked ? ' checked' : '' ?>>
                            <span>
                                <strong><?= e($language['label']) ?></strong>
                                <small><?= e(strtoupper((string) $language['code'])) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="choice-row">
                    <button class="btn btn-primary" type="submit"><?= icon('check') ?> <span><?= et('common.continue') ?></span></button>
                </div>
            </form>
        </div>
    </article>
    <?php
}

function tc_install_database_view(array $state): void
{
    $database = is_array($state['database'] ?? null) ? $state['database'] : (array) config('database', []);
    ?>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('database') ?> <?= et('install.db_title') ?></h2>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/install?step=db">
                <?= csrf_field() ?>
                <input type="hidden" name="_install_step" value="db">
                <div class="grid sm:grid-2">
                    <label class="field">
                        <span class="label"><?= et('install.db_host') ?></span>
                        <input class="input" name="host" value="<?= e($database['host'] ?? 'localhost') ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('install.db_port') ?></span>
                        <input class="input" type="number" name="port" min="1" max="65535" value="<?= e($database['port'] ?? '') ?>">
                    </label>
                    <label class="field">
                        <span class="label"><?= et('install.db_name') ?></span>
                        <input class="input" name="name" value="<?= e($database['name'] ?? '') ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('install.db_user') ?></span>
                        <input class="input" name="user" value="<?= e($database['user'] ?? 'root') ?>" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('install.db_password') ?></span>
                        <input class="input" type="password" name="password" value="<?= e($database['password'] ?? '') ?>">
                    </label>
                    <label class="field">
                        <span class="label"><?= et('install.db_charset') ?></span>
                        <input class="input" name="charset" value="<?= e($database['charset'] ?? 'utf8mb4') ?>" required>
                    </label>
                </div>
                <div class="choice-row">
                    <a class="btn btn-secondary" href="/install?step=language"><?= icon('arrow-left') ?> <span><?= et('common.back') ?></span></a>
                    <button class="btn btn-primary" type="submit"><?= icon('arrow-right') ?> <span><?= et('install.db_test') ?></span></button>
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
            Core::setDb(tc_install_pdo($state['database']));

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
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('database') ?> <?= et('install.tables_title') ?></h2>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= et('install.tables_intro') ?></p>
            <?php if ($error !== null): ?>
                <div class="alert alert-danger"><?= et('install.messages.db_failed', ['message' => $error]) ?></div>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= et('install.table_name') ?></th>
                            <th><?= et('install.table_purpose') ?></th>
                            <th><?= et('install.table_state') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (tc_install_schema_tables() as $table => $purpose): ?>
                            <?php $exists = (bool) ($tableStatuses[$table] ?? false); ?>
                            <tr>
                                <td><code><?= e($table) ?></code></td>
                                <td><?= et($purpose) ?></td>
                                <td>
                                    <span class="badge<?= $exists ? ' badge-primary' : '' ?>">
                                        <?= $exists ? et('install.state_exists') : et('install.state_missing') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" action="/install?step=tables" class="choice-row">
                <?= csrf_field() ?>
                <input type="hidden" name="_install_step" value="tables">
                <a class="btn btn-secondary" href="/install?step=db"><?= icon('arrow-left') ?> <span><?= et('common.back') ?></span></a>
                <button class="btn btn-primary" type="submit"><?= icon('check') ?> <span><?= et('install.create_tables') ?></span></button>
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
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('shield') ?> <?= et('install.admin_title') ?></h2>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/install?step=admin">
                <?= csrf_field() ?>
                <input type="hidden" name="_install_step" value="admin">
                <label class="field">
                    <span class="label"><?= et('common.username') ?></span>
                    <input class="input" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" required>
                    <span class="help"><?= e(username_hint()) ?></span>
                </label>
                <div class="grid sm:grid-2">
                    <label class="field">
                        <span class="label"><?= et('common.password') ?></span>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('install.admin_password_confirm') ?></span>
                        <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" required>
                    </label>
                </div>
                <div class="choice-row">
                    <a class="btn btn-secondary" href="/install?step=tables"><?= icon('arrow-left') ?> <span><?= et('common.back') ?></span></a>
                    <button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.finish') ?></span></button>
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
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('check-circle') ?> <?= et('install.done_title') ?></h2>
        </div>
        <div class="card-body stack">
            <?php if ((bool) config('install.installed', false)): ?>
                <div class="alert alert-info"><?= et('install.already_installed') ?></div>
            <?php endif; ?>
            <p class="text-muted mb-0"><?= et('install.done_intro') ?></p>
            <ul class="result-list">
                <li class="result-item"><?= icon('globe') ?> <span><?= et('common.language') ?>: <strong><?= e(locale()) ?></strong></span></li>
                <li class="result-item"><?= icon('database') ?> <span><?= et('common.tables') ?>: <strong>users, content, terms, content_tags, links, content_links, content_shares, content_reactions, content_comments, comment_likes, user_followers, notifications, content_reports, blocked_domains, user_action_limits, settings</strong></span></li>
                <li class="result-item"><?= icon('shield') ?> <span><?= et('common.account') ?>: <strong><?= et('common.done') ?></strong></span></li>
            </ul>
            <div class="btn-group">
                <a class="btn btn-primary" href="/admin"><?= icon('dashboard') ?> <span><?= et('install.open_admin') ?></span></a>
                <a class="btn btn-secondary" href="/"><?= icon('home') ?> <span><?= et('install.open_index') ?></span></a>
            </div>
        </div>
    </article>
    <?php
}
