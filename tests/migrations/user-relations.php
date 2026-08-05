<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/functions.php';

$databaseConfig = (array) config('database', []);
$driver = (string) ($databaseConfig['driver'] ?? 'mysql');
if ($driver !== 'mysql') {
    echo "SKIP user relations migration: a local MySQL database is required.\n";
    exit(0);
}

$host = (string) ($databaseConfig['host'] ?? 'localhost');
$port = isset($databaseConfig['port']) ? ';port=' . (int) $databaseConfig['port'] : '';
$charset = (string) ($databaseConfig['charset'] ?? 'utf8mb4');
$temporaryDatabase = 'tinycat_migration_test_' . strtolower(bin2hex(random_bytes(5)));

if (preg_match('/^tinycat_migration_test_[a-f0-9]{10}$/', $temporaryDatabase) !== 1) {
    throw new RuntimeException('Unsafe temporary database name.');
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = new PDO(
    sprintf('mysql:host=%s%s;charset=%s', $host, $port, $charset),
    (string) ($databaseConfig['user'] ?? ''),
    (string) ($databaseConfig['password'] ?? ''),
    $options
);
$created = false;

try {
    try {
        $server->exec('CREATE DATABASE `' . $temporaryDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    } catch (PDOException $exception) {
        echo 'SKIP user relations migration: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $testDatabase = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $temporaryDatabase, $charset),
        (string) ($databaseConfig['user'] ?? ''),
        (string) ($databaseConfig['password'] ?? ''),
        $options
    );
    Core::setDb($testDatabase);

    // Compact 1.0.4-style schema: enough to exercise the production migration
    // without copying or locking data from the developer's live database.
    $baseline = [
        'CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(32) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT \'user\',
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            avatar_config TEXT NULL,
            muted_by INT UNSIGNED NULL,
            PRIMARY KEY (id), UNIQUE KEY users_username_unique (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            body VARCHAR(2000) NOT NULL,
            author_id INT UNSIGNED NULL,
            published_at DATETIME NOT NULL,
            edit_locked_by INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE terms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_tags (
            content_id BIGINT UNSIGNED NOT NULL,
            term_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (content_id, term_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_links (
            content_id BIGINT UNSIGNED NOT NULL,
            link_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (content_id, link_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_likes (
            content_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (content_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            content_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL,
            body VARCHAR(2000) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE comment_likes (
            comment_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (comment_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE user_followers (
            user_id INT UNSIGNED NOT NULL,
            follower_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, follower_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE user_profile_links (
            user_id INT UNSIGNED NOT NULL,
            link_type VARCHAR(32) NOT NULL,
            PRIMARY KEY (user_id, link_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            actor_id INT UNSIGNED NOT NULL,
            content_id BIGINT UNSIGNED NULL,
            comment_id BIGINT UNSIGNED NULL,
            type VARCHAR(40) NOT NULL,
            notification_key VARCHAR(190) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY notifications_key_unique (user_id, notification_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE content_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            content_id BIGINT UNSIGNED NOT NULL,
            reporter_id INT UNSIGNED NOT NULL,
            reason VARCHAR(40) NOT NULL DEFAULT \'other\',
            status VARCHAR(20) NOT NULL DEFAULT \'open\',
            reviewed_by INT UNSIGNED NULL,
            PRIMARY KEY (id), UNIQUE KEY content_reports_unique (content_id, reporter_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE bot_sources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            feed_url VARCHAR(2048) NOT NULL,
            feed_hash CHAR(64) NOT NULL,
            post_template VARCHAR(2000) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY bot_sources_feed_hash_unique (feed_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE bot_feed_items (
            source_id BIGINT UNSIGNED NOT NULL,
            item_hash CHAR(64) NOT NULL,
            content_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (source_id, item_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE bot_feed_history (
            bot_user_id INT UNSIGNED NOT NULL,
            feed_hash CHAR(64) NOT NULL,
            item_hash CHAR(64) NOT NULL,
            content_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (bot_user_id, feed_hash, item_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE bot_source_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            bot_user_id INT UNSIGNED NOT NULL,
            content_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];

    foreach ($baseline as $statement) {
        $testDatabase->exec($statement);
    }

    $migration = require dirname(__DIR__, 2) . '/migrations/20260805_001_user_relations.php';

    if (!is_callable($migration)) {
        throw new RuntimeException('The user relations migration is not callable.');
    }

    $migration($testDatabase);
    $migration($testDatabase);

    $constraintCount = (int) val(
        'SELECT COUNT(*)
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME LIKE ?',
        ['fk_%']
    );

    if ($constraintCount !== 33) {
        throw new RuntimeException('Expected 33 foreign keys, found ' . $constraintCount . '.');
    }

    $suffix = strtolower(bin2hex(random_bytes(4)));
    $keeperId = (int) insert('users', [
        'username' => 'keeper_' . $suffix,
        'role' => 'user',
        'status' => 'active',
    ]);
    $deletedId = (int) insert('users', [
        'username' => 'deleted_' . $suffix,
        'role' => 'user',
        'status' => 'active',
    ]);
    $deletedContentId = (int) insert('content', [
        'body' => 'Deleted author content',
        'author_id' => $deletedId,
        'published_at' => date_db(),
    ]);
    $keeperContentId = (int) insert('content', [
        'body' => 'Keeper content',
        'author_id' => $keeperId,
        'published_at' => date_db(),
        'edit_locked_by' => $deletedId,
    ]);
    $deletedCommentId = (int) insert('content_comments', [
        'content_id' => $keeperContentId,
        'user_id' => $deletedId,
        'body' => 'Deleted author comment',
    ]);
    insert('content_likes', ['content_id' => $keeperContentId, 'user_id' => $deletedId]);
    insert('comment_likes', ['comment_id' => $deletedCommentId, 'user_id' => $keeperId]);
    insert('user_followers', ['user_id' => $keeperId, 'follower_id' => $deletedId]);
    insert('notifications', [
        'user_id' => $keeperId,
        'actor_id' => $deletedId,
        'content_id' => $keeperContentId,
        'type' => 'like',
        'notification_key' => 'migration-actor-' . $suffix,
    ]);
    insert('notifications', [
        'user_id' => $deletedId,
        'actor_id' => $keeperId,
        'content_id' => $keeperContentId,
        'type' => 'like',
        'notification_key' => 'migration-recipient-' . $suffix,
    ]);
    insert('content_reports', [
        'content_id' => $keeperContentId,
        'reporter_id' => $deletedId,
        'reason' => 'other',
        'status' => 'resolved',
        'reviewed_by' => $deletedId,
    ]);
    insert('bot_sources', [
        'bot_user_id' => $deletedId,
        'name' => 'Migration source ' . $suffix,
        'feed_url' => 'https://example.com/' . $suffix . '.xml',
        'feed_hash' => hash('sha256', 'migration-' . $suffix),
        'post_template' => '{title}',
    ]);
    update('users', ['muted_by' => $deletedId], ['id' => $keeperId]);

    user_delete_account($deletedId);

    $expectations = [
        'deleted user' => (int) val('SELECT COUNT(*) FROM users WHERE id = ?', [$deletedId]) === 0,
        'authored content cascade' => (int) val('SELECT COUNT(*) FROM content WHERE id = ?', [$deletedContentId]) === 0,
        'keeper content preserved' => (int) val('SELECT COUNT(*) FROM content WHERE id = ?', [$keeperContentId]) === 1,
        'comment cascade' => (int) val('SELECT COUNT(*) FROM content_comments WHERE user_id = ?', [$deletedId]) === 0,
        'content like cascade' => (int) val('SELECT COUNT(*) FROM content_likes WHERE user_id = ?', [$deletedId]) === 0,
        'comment like cascade' => (int) val('SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?', [$deletedCommentId]) === 0,
        'follow cascade' => (int) val('SELECT COUNT(*) FROM user_followers WHERE follower_id = ?', [$deletedId]) === 0,
        'recipient notifications cascade' => (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$deletedId]) === 0,
        'notification actor retained anonymously' => val('SELECT actor_id FROM notifications WHERE notification_key = ?', ['migration-actor-' . $suffix]) === null,
        'reporter retained anonymously' => val('SELECT reporter_id FROM content_reports WHERE content_id = ?', [$keeperContentId]) === null,
        'reviewer retained anonymously' => val('SELECT reviewed_by FROM content_reports WHERE content_id = ?', [$keeperContentId]) === null,
        'mute audit cleared' => val('SELECT muted_by FROM users WHERE id = ?', [$keeperId]) === null,
        'edit lock audit cleared' => val('SELECT edit_locked_by FROM content WHERE id = ?', [$keeperContentId]) === null,
        'bot source cascade' => (int) val('SELECT COUNT(*) FROM bot_sources WHERE bot_user_id = ?', [$deletedId]) === 0,
    ];

    foreach ($expectations as $label => $passed) {
        if (!$passed) {
            throw new RuntimeException('Failed expectation: ' . $label . '.');
        }
    }

    echo 'PASS user relations migration: 33 keys, restart safety, CASCADE and SET NULL behavior.' . PHP_EOL;
} finally {
    if ($created && preg_match('/^tinycat_migration_test_[a-f0-9]{10}$/', $temporaryDatabase) === 1) {
        $server->exec('DROP DATABASE IF EXISTS `' . $temporaryDatabase . '`');
    }
}
