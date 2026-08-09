<?php
declare(strict_types=1);

$workspaceRoot = dirname(__DIR__, 2);
$options = getopt('', ['root::']);
$requestedRoot = trim((string) ($options['root'] ?? $workspaceRoot));
$root = realpath($requestedRoot);
$configPath = $workspaceRoot . '/config.php';

if (!is_string($root)
    || !is_file($root . '/App/bootstrap.php')
    || !is_file($root . '/Public/install/index.php')
) {
    fwrite(STDERR, "MySQL installer rehearsal root is invalid.\n");
    exit(1);
}

if (!is_file($configPath)) {
    echo "SKIP MySQL installer rehearsal: local database configuration is unavailable.\n";
    exit(0);
}

$baseConfig = require $configPath;
$databaseConfig = is_array($baseConfig['database'] ?? null) ? $baseConfig['database'] : [];
$configHash = hash_file('sha256', $configPath);

if (($databaseConfig['host'] ?? '') === '' || !array_key_exists('user', $databaseConfig)) {
    echo "SKIP MySQL installer rehearsal: local MySQL connection is not configured.\n";
    exit(0);
}

$host = (string) $databaseConfig['host'];
$port = isset($databaseConfig['port']) ? ';port=' . (int) $databaseConfig['port'] : '';
$charset = preg_match('/^[A-Za-z0-9_]+$/', (string) ($databaseConfig['charset'] ?? 'utf8mb4')) === 1
    ? (string) ($databaseConfig['charset'] ?? 'utf8mb4')
    : 'utf8mb4';
$databaseName = 'tinycat_installer_test_' . bin2hex(random_bytes(6));
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = null;
$database = null;
$created = false;
$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    if (preg_match('/^tinycat_installer_test_[a-f0-9]{12}$/', $databaseName) !== 1) {
        throw new RuntimeException('Unsafe installer rehearsal database name.');
    }

    try {
        $server = new PDO(
            "mysql:host={$host}{$port};charset={$charset}",
            (string) $databaseConfig['user'],
            (string) ($databaseConfig['password'] ?? ''),
            $options
        );
    } catch (Throwable) {
        echo "SKIP MySQL installer rehearsal: configured server is unavailable.\n";
        exit(0);
    }

    try {
        $server->exec("CREATE DATABASE `{$databaseName}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci");
        $created = true;
    } catch (PDOException $exception) {
        echo 'SKIP MySQL installer rehearsal: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        "mysql:host={$host}{$port};dbname={$databaseName};charset={$charset}",
        (string) $databaseConfig['user'],
        (string) ($databaseConfig['password'] ?? ''),
        $options
    );
    define('TINYCAT', true);
    require_once $root . '/App/bootstrap.php';
    ob_start();

    try {
        require $root . '/Public/install/index.php';
    } finally {
        ob_end_clean();
    }

    Core::setDb($database);
    tc_install_create_tables();
    $assert(tc_install_missing_tables() === [], 'Fresh installer creates every required table.');
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profile_links'")->fetchColumn() === 0,
        'Fresh installer does not create the obsolete profile links table.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content' AND COLUMN_NAME = 'created_at'")->fetchColumn() === 0,
        'Fresh installer does not create the redundant content creation timestamp.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'links' AND COLUMN_NAME = 'embed_url'")->fetchColumn() === 0,
        'Fresh installer does not create the redundant link embed URL.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates'")->fetchColumn() === 0,
        'Fresh installer does not create the redundant email template table.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_followers' AND INDEX_NAME = 'user_followers_follower_recent_index'")->fetchColumn() === 3,
        'Fresh installer creates the recent-followers index.'
    );
    $assert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_followers' AND INDEX_NAME = 'user_followers_follower_index'")->fetchColumn() === 0,
        'Fresh installer omits the obsolete followers index.'
    );
    $firstTableCount = (int) $database->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();

    Core::setDb(new PDO(
        "mysql:host={$host}{$port};dbname={$databaseName};charset={$charset}",
        (string) $databaseConfig['user'],
        (string) ($databaseConfig['password'] ?? ''),
        $options
    ));
    tc_install_create_tables();
    $secondTableCount = (int) db()->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
    $assert($secondTableCount === $firstTableCount, 'Repeating table creation is restart-safe.');
    $assert(tc_install_missing_tables() === [], 'Restarted installer still recognizes the complete schema.');

    $password = 'Installer-Rehearsal-2026';
    $firstAdmin = tc_install_create_admin_account('installer_stage1', $password, 'en');
    $secondAdmin = tc_install_create_admin_account('installer_stage1', $password, 'cs');
    $assert($firstAdmin > 0 && $secondAdmin === $firstAdmin, 'Admin creation updates the same account after restart.');
    $assert(
        (int) val("SELECT COUNT(*) FROM users WHERE username = 'installer_stage1' AND role = 'admin' AND locale = 'cs'") === 1,
        'Restarted admin step persists one current administrator.'
    );

    tc_install_default_settings(['locale' => 'en']);
    $settingsCount = (int) val('SELECT COUNT(*) FROM settings');
    $emailTemplateStates = setting('email.templates', []);
    tc_install_default_settings(['locale' => 'cs']);
    $assert((int) val('SELECT COUNT(*) FROM settings') === $settingsCount, 'Default settings are idempotent.');
    $assert((int) val('SELECT COUNT(DISTINCT setting_key) FROM settings') === $settingsCount, 'Default settings remain unique.');
    $assert(setting('email.templates', []) === $emailTemplateStates, 'Email delivery switches are idempotent settings.');
    $assert((string) val("SELECT setting_value FROM settings WHERE setting_key = 'i18n.locale'") === 'cs', 'Repeated defaults update the selected locale.');

    $status = app_db_status();
    $assert(($status['connected'] ?? false) === true, 'Installed database is connected.');
    $assert(($status['missing_tables'] ?? null) === [], 'Installed database reports no missing tables.');
    $assert(($status['account_ready'] ?? false) === true, 'Installed database reports an active account.');
    $assert(($status['ready'] ?? false) === true, 'Installed database is application-ready.');
    $assert(is_string($configHash) && hash_file('sha256', $configPath) === $configHash, 'Schema rehearsal does not replace application configuration.');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
} finally {
    $database = null;

    if ($created && $server instanceof PDO && preg_match('/^tinycat_installer_test_[a-f0-9]{12}$/', $databaseName) === 1) {
        try {
            $server->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
        } catch (Throwable $exception) {
            $failures[] = 'Unable to remove installer rehearsal database: ' . $exception->getMessage();
        }
    }

    $server = null;
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    echo "\nMySQL installer rehearsal: {$checks} checks, " . count($failures) . " failures.\n";
    exit(1);
}

echo "PASS restart-safe MySQL installer rehearsal ({$checks} checks, disposable database removed)\n";
