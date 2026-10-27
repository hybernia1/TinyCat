<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

$config = (array) \TinyCat\Settings\Store::config('database', []);

if (($config['driver'] ?? 'mysql') !== 'mysql') {
    echo "SKIP cron tasks: a local MySQL database is required.\n";
    exit(0);
}

$host = (string) ($config['host'] ?? 'localhost');
$port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
$charset = (string) ($config['charset'] ?? 'utf8mb4');
$databaseName = 'tinycat_cron_test_' . strtolower(bin2hex(random_bytes(5)));
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

if (preg_match('/^tinycat_cron_test_[a-f0-9]{10}$/', $databaseName) !== 1) {
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
        echo 'SKIP cron tasks: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );
    \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);

    foreach ([
        'CREATE TABLE settings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(120) NOT NULL,
            setting_group VARCHAR(60) NOT NULL DEFAULT \'general\',
            setting_value LONGTEXT NULL,
            setting_type VARCHAR(20) NOT NULL DEFAULT \'string\',
            autoload TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY settings_key_unique (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            body VARCHAR(20) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE terms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY terms_name_unique (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_tags (
            content_id BIGINT UNSIGNED NOT NULL,
            term_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (content_id, term_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            normalized_url VARCHAR(255) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_links (
            content_id BIGINT UNSIGNED NOT NULL,
            link_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (content_id, link_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_images (
            content_id BIGINT UNSIGNED NOT NULL,
            path VARCHAR(190) NOT NULL,
            width INT UNSIGNED NOT NULL,
            height INT UNSIGNED NOT NULL,
            bytes INT UNSIGNED NOT NULL,
            PRIMARY KEY (content_id),
            UNIQUE KEY content_images_path_unique (path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE ip_action_limits (
            ip_address VARCHAR(45) NOT NULL,
            action_name VARCHAR(40) NOT NULL,
            bucket_start DATETIME NOT NULL,
            action_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (ip_address, action_name, bucket_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            read_at DATETIME NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ] as $statement) {
        $database->exec($statement);
    }

    \TinyCat\Database\Connection::insert('content', ['body' => 'test']);
    $contentId = (int) \TinyCat\Database\Connection::get()->lastInsertId();
    $imageDirectory = \TinyCat\Support\Paths::basePath('uploads/status-images/2099/01');
    $orphanImagePath = '2099/01/' . bin2hex(random_bytes(16)) . '.webp';
    $storedImagePath = '2099/01/' . bin2hex(random_bytes(16)) . '.webp';
    $orphanImageFile = \TinyCat\Support\Paths::basePath('uploads/status-images/' . $orphanImagePath);
    $storedImageFile = \TinyCat\Support\Paths::basePath('uploads/status-images/' . $storedImagePath);

    if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0775, true) && !is_dir($imageDirectory)) {
        throw new RuntimeException('Could not create the temporary status image directory.');
    }

    file_put_contents($orphanImageFile, 'orphan');
    file_put_contents($storedImageFile, 'stored');
    touch($orphanImageFile, time() - 7200);
    touch($storedImageFile, time() - 7200);
    \TinyCat\Database\Connection::insert('content_images', [
        'content_id' => $contentId,
        'path' => $storedImagePath,
        'width' => 1,
        'height' => 1,
        'bytes' => 6,
    ]);
    \TinyCat\Database\Connection::insert('terms', ['name' => 'used']);
    $usedTermId = (int) \TinyCat\Database\Connection::get()->lastInsertId();
    \TinyCat\Database\Connection::insert('terms', ['name' => 'orphan']);
    \TinyCat\Database\Connection::insert('content_tags', ['content_id' => $contentId, 'term_id' => $usedTermId]);
    \TinyCat\Database\Connection::insert('links', ['normalized_url' => 'https://used.test/']);
    $usedLinkId = (int) \TinyCat\Database\Connection::get()->lastInsertId();
    \TinyCat\Database\Connection::insert('links', ['normalized_url' => 'https://orphan.test/']);
    \TinyCat\Database\Connection::insert('content_links', ['content_id' => $contentId, 'link_id' => $usedLinkId]);

    \TinyCat\Database\Connection::insert('ip_action_limits', [
        'ip_address' => '192.0.2.1',
        'action_name' => 'old',
        'bucket_start' => \TinyCat\Support\Dates::dateDb('-31 days'),
        'action_count' => 1,
    ]);
    \TinyCat\Database\Connection::insert('ip_action_limits', [
        'ip_address' => '192.0.2.1',
        'action_name' => 'current',
        'bucket_start' => \TinyCat\Support\Dates::dateDb('-1 day'),
        'action_count' => 1,
    ]);
    \TinyCat\Database\Connection::insert('password_reset_tokens', ['expires_at' => \TinyCat\Support\Dates::dateDb('-1 hour'), 'used_at' => null]);
    \TinyCat\Database\Connection::insert('password_reset_tokens', ['expires_at' => \TinyCat\Support\Dates::dateDb('+1 day'), 'used_at' => \TinyCat\Support\Dates::dateDb('-1 hour')]);
    \TinyCat\Database\Connection::insert('password_reset_tokens', ['expires_at' => \TinyCat\Support\Dates::dateDb('+1 day'), 'used_at' => null]);
    \TinyCat\Database\Connection::insert('notifications', ['read_at' => \TinyCat\Support\Dates::dateDb('-31 days')]);
    \TinyCat\Database\Connection::insert('notifications', ['read_at' => \TinyCat\Support\Dates::dateDb('-1 day')]);
    \TinyCat\Database\Connection::insert('notifications', ['read_at' => null]);

    $schedulerMigration = require dirname(__DIR__, 2) . '/migrations/20261026_003_scheduler_runs.php';
    $schedulerMigration($database);
    $schedulerMigration($database);
    foreach (['-31 days', '-1 day'] as $age) {
        \TinyCat\Database\Connection::insert('scheduler_runs', ['task' => 'cleanup', 'mode' => 'cli', 'status' => 'completed', 'started_at' => \TinyCat\Support\Dates::dateDb($age), 'options_json' => '{}']);
    }
    $historyCleanup = \TinyCat\Scheduler\Cleanup::cleanup_task_run('old_scheduler_runs', 500);
    if ($historyCleanup['changed'] !== 1 || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM scheduler_runs') !== 1) {
        throw new RuntimeException('Scheduler history retention must preserve recent runs.');
    }


    $database->exec('CREATE TABLE bot_sources (id BIGINT PRIMARY KEY, bot_user_id BIGINT, feed_hash VARCHAR(64))');
    $database->exec('CREATE TABLE bot_source_runs (id BIGINT AUTO_INCREMENT PRIMARY KEY, source_id BIGINT, status VARCHAR(24), started_at DATETIME)');
    $database->exec('CREATE TABLE bot_feed_history (bot_user_id BIGINT, feed_hash VARCHAR(64), created_at DATETIME)');
    $botMigration = require dirname(__DIR__, 2) . '/migrations/20261026_004_bot_scheduler_runs.php';
    $botMigration($database); $botMigration($database);
    $database->exec("INSERT INTO bot_sources VALUES (1, 1, 'active')");
    for ($index = 0; $index < 55; $index++) {
        \TinyCat\Database\Connection::insert('bot_source_runs', ['source_id' => 1, 'status' => 'current', 'started_at' => \TinyCat\Support\Dates::dateDb()]);
    }
    foreach (['running', 'error', 'interrupted'] as $status) {
        \TinyCat\Database\Connection::insert('bot_source_runs', ['source_id' => 1, 'status' => $status, 'started_at' => \TinyCat\Support\Dates::dateDb('-15 days')]);
    }
    foreach (['active', 'orphan'] as $hash) {
        \TinyCat\Database\Connection::insert('bot_feed_history', ['bot_user_id' => 1, 'feed_hash' => $hash, 'created_at' => \TinyCat\Support\Dates::dateDb('-15 days')]);
    }
    $botCleanup = \TinyCat\Scheduler\Cleanup::cleanup_task_run('bot_history', 500);
    if ($botCleanup['changed'] !== 8 || (int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM bot_source_runs WHERE status = 'current'") !== 50
        || (int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM bot_source_runs WHERE status = 'running'") !== 1
        || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM bot_feed_history') !== 1) {
        throw new RuntimeException('Common cleanup must bound bot diagnostics and preserve active imports and duplicate protection.');
    }

    \TinyCat\Database\Connection::insert('bot_source_runs', ['source_id' => 1, 'status' => 'error', 'started_at' => \TinyCat\Support\Dates::dateDb('-15 days')]);
    \TinyCat\Database\Connection::insert('bot_feed_history', ['bot_user_id' => 1, 'feed_hash' => 'forced-orphan', 'created_at' => \TinyCat\Support\Dates::dateDb('-15 days')]);
    $database->exec("CREATE TRIGGER fail_bot_history_cleanup BEFORE DELETE ON bot_feed_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced cleanup failure'");
    $partialCleanup = \TinyCat\Scheduler\Cleanup::cleanup_task_run('bot_history', 500);
    $database->exec('DROP TRIGGER fail_bot_history_cleanup');
    if (($partialCleanup['changed'] ?? 0) !== 1 || empty($partialCleanup['error']) || $partialCleanup['done']) {
        throw new RuntimeException('Partial cleanup failures must retain completed changes and report unfinished work.');
    }

    $databaseCleanupTasks = array_values(array_filter(
        \TinyCat\Scheduler\Cleanup::cron_cleanup_tasks(),
        static fn (string $task): bool => !in_array($task, ['orphan_status_image_files', 'orphan_link_image_files', 'expired_cache_files', 'old_scheduler_runs', 'bot_history'], true)
    ));
    $result = \TinyCat\Scheduler\Cleanup::cron_cleanup_run(500, true, $databaseCleanupTasks);
    $imageCleanup = \TinyCat\Status\ImageStorage::cleanupOrphans(500, '2099/01');
    $changed = array_map(
        static fn (array $task): int => (int) ($task['changed'] ?? 0),
        (array) ($result['results'] ?? [])
    );

    if ($changed !== [
        'orphan_terms' => 1,
        'orphan_links' => 1,
        'old_action_limits' => 1,
        'old_password_reset_tokens' => 2,
        'old_read_notifications' => 1,
    ]) {
        throw new RuntimeException('Scheduled database cleanup changed an unexpected number of rows.');
    }

    if ((int) ($imageCleanup['changed'] ?? 0) !== 1 || !empty($imageCleanup['has_more'])) {
        throw new RuntimeException('Scheduled status image cleanup changed an unexpected number of files.');
    }

    if ((int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM terms') !== 1
        || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM links') !== 1
        || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM content_tags') !== 1
        || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM content_links') !== 1
        || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM ip_action_limits') !== 1
        || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM password_reset_tokens') !== 1
        || (int) \TinyCat\Database\Connection::value('SELECT COUNT(*) FROM notifications') !== 2
        || !is_file($storedImageFile)
        || is_file($orphanImageFile)) {
        throw new RuntimeException('Scheduled cleanup did not preserve current rows.');
    }

    $deferred = \TinyCat\Scheduler\Cleanup::cron_cleanup_run(500, false, $databaseCleanupTasks);

    if (!empty($deferred['due']) || (int) \TinyCat\Settings\Store::setting('cron.cleanup_last_run', 0) < time() - 5) {
        throw new RuntimeException('The hourly cleanup interval was not recorded.');
    }

    echo "PASS unified scheduled tasks: orphan data cleaned and current rows retained.\n";
} finally {
    foreach ([$orphanImageFile ?? '', $storedImageFile ?? ''] as $file) {
        if ($file !== '' && is_file($file)) {
            unlink($file);
        }
    }

    if (isset($imageDirectory) && is_dir($imageDirectory) && !(new FilesystemIterator($imageDirectory))->valid()) {
        rmdir($imageDirectory);
        $yearDirectory = dirname($imageDirectory);
        if (is_dir($yearDirectory) && !(new FilesystemIterator($yearDirectory))->valid()) {
            rmdir($yearDirectory);
        }
    }

    if ($created && preg_match('/^tinycat_cron_test_[a-f0-9]{10}$/', $databaseName) === 1) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
