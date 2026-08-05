<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

$config = (array) config('database', []);

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
    Core::setDb($database);

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

    insert('content', ['body' => 'test']);
    $contentId = (int) db()->lastInsertId();
    insert('terms', ['name' => 'used']);
    $usedTermId = (int) db()->lastInsertId();
    insert('terms', ['name' => 'orphan']);
    insert('content_tags', ['content_id' => $contentId, 'term_id' => $usedTermId]);
    insert('links', ['normalized_url' => 'https://used.test/']);
    $usedLinkId = (int) db()->lastInsertId();
    insert('links', ['normalized_url' => 'https://orphan.test/']);
    insert('content_links', ['content_id' => $contentId, 'link_id' => $usedLinkId]);

    insert('ip_action_limits', [
        'ip_address' => '192.0.2.1',
        'action_name' => 'old',
        'bucket_start' => date_db('-31 days'),
        'action_count' => 1,
    ]);
    insert('ip_action_limits', [
        'ip_address' => '192.0.2.1',
        'action_name' => 'current',
        'bucket_start' => date_db('-1 day'),
        'action_count' => 1,
    ]);
    insert('password_reset_tokens', ['expires_at' => date_db('-1 hour'), 'used_at' => null]);
    insert('password_reset_tokens', ['expires_at' => date_db('+1 day'), 'used_at' => date_db('-1 hour')]);
    insert('password_reset_tokens', ['expires_at' => date_db('+1 day'), 'used_at' => null]);
    insert('notifications', ['read_at' => date_db('-31 days')]);
    insert('notifications', ['read_at' => date_db('-1 day')]);
    insert('notifications', ['read_at' => null]);

    $result = cron_cleanup_run(500, true);
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
        throw new RuntimeException('Scheduled cleanup changed an unexpected number of rows.');
    }

    if ((int) val('SELECT COUNT(*) FROM terms') !== 1
        || (int) val('SELECT COUNT(*) FROM links') !== 1
        || (int) val('SELECT COUNT(*) FROM content_tags') !== 1
        || (int) val('SELECT COUNT(*) FROM content_links') !== 1
        || (int) val('SELECT COUNT(*) FROM ip_action_limits') !== 1
        || (int) val('SELECT COUNT(*) FROM password_reset_tokens') !== 1
        || (int) val('SELECT COUNT(*) FROM notifications') !== 2) {
        throw new RuntimeException('Scheduled cleanup did not preserve current rows.');
    }

    $deferred = cron_cleanup_run(500);

    if (!empty($deferred['due']) || (int) setting('cron.cleanup_last_run', 0) < time() - 5) {
        throw new RuntimeException('The hourly cleanup interval was not recorded.');
    }

    echo "PASS unified scheduled tasks: orphan data cleaned and current rows retained.\n";
} finally {
    if ($created && preg_match('/^tinycat_cron_test_[a-f0-9]{10}$/', $databaseName) === 1) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
