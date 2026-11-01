<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__);
$migrate = require $root . '/migrations/20261101_001_content_status.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$exercise = static function (PDO $database, bool $mysql) use ($migrate, $assert): void {
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if (!$mysql) { $database->exec('PRAGMA foreign_keys = ON'); }
    $id = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $database->exec('CREATE TABLE users (id ' . $id . ' PRIMARY KEY)');
    $database->exec('INSERT INTO users VALUES (1), (2)');
    $database->exec('CREATE TABLE content (
        id ' . $id . ' PRIMARY KEY, author_id ' . $id . ' NOT NULL, body TEXT, published_at DATETIME,
        edit_locked_at DATETIME, edit_locked_by ' . $id . ', edit_lock_reason VARCHAR(80),
        FOREIGN KEY (author_id) REFERENCES users(id),
        CONSTRAINT fk_content_edit_locked_by FOREIGN KEY (edit_locked_by) REFERENCES users(id) ON DELETE SET NULL
    )');
    $database->exec("INSERT INTO content VALUES
        (1, 1, 'Public', '2020-01-01', NULL, NULL, NULL),
        (2, 1, 'Hidden evidence', '2020-01-02', '2020-02-01', 2, 'moderation_hide'),
        (3, 1, 'Previously locked', '2020-01-03', '2020-02-01', 2, 'moderation_keep')");
    $database->exec('CREATE TABLE content_reports (
        id ' . $id . ' PRIMARY KEY, content_id ' . $id . ' NOT NULL, reporter_id ' . $id . ',
        reason VARCHAR(40), note TEXT, status VARCHAR(20), created_at DATETIME,
        reviewed_at DATETIME, reviewed_by ' . $id . ', action_note TEXT,
        CONSTRAINT content_reports_unique UNIQUE (content_id, reporter_id),
        FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )');
    $database->exec("INSERT INTO content_reports VALUES
        (1, 2, 2, 'spam', 'Keep the note', 'resolved', '2020-02-01', '2020-02-02', 1, 'hidden'),
        (2, 3, 2, 'abuse', 'Dismissed note', 'dismissed', '2020-02-01', '2020-02-02', 1, 'kept_locked')");
    $database->exec('CREATE TABLE content_comments (id INTEGER PRIMARY KEY, content_id ' . $id . ', body TEXT, FOREIGN KEY(content_id) REFERENCES content(id) ON DELETE CASCADE)');
    $database->exec("INSERT INTO content_comments VALUES (1, 2, 'Preserved discussion')");
    $reports = $database->query('SELECT * FROM content_reports ORDER BY id')->fetchAll();
    $migrate($database);
    $assert($database->query('SELECT status FROM content ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['published', 'hidden', 'published'], 'Only explicitly hidden legacy posts become hidden.');
    $assert($database->query('SELECT * FROM content_reports ORDER BY id')->fetchAll() === $reports, 'Report IDs, notes and decisions survive the migration.');
    $assert($database->query('SELECT body FROM content_comments WHERE id = 1')->fetchColumn() === 'Preserved discussion', 'Discussion survives the schema change.');
    $columns = $mysql ? $database->query('SHOW COLUMNS FROM content')->fetchAll() : $database->query('PRAGMA table_info(content)')->fetchAll();
    $names = array_column($columns, $mysql ? 'Field' : 'name');
    $assert(!array_intersect($names, ['edit_locked_at', 'edit_locked_by', 'edit_lock_reason']), 'All three edit-lock columns are removed.');
    $database->exec("UPDATE content SET status = 'published' WHERE id = 2");
    $migrate($database);
    $assert($database->query('SELECT status FROM content WHERE id = 2')->fetchColumn() === 'published', 'A repeated migration preserves later administrative publication.');
    $database->exec("INSERT INTO content (id, author_id, body, published_at) VALUES (4, 1, 'New post', '2020-03-01')");
    $assert($database->query('SELECT status FROM content WHERE id = 4')->fetchColumn() === 'published', 'New content defaults to published independently of reports.');
    $database->exec("INSERT INTO content_reports (id, content_id, reporter_id, reason, status, created_at) VALUES (3, 3, 2, 'spam', 'open', '2020-03-01')");
    $assert((int) $database->query('SELECT COUNT(*) FROM content_reports WHERE content_id = 3 AND reporter_id = 2')->fetchColumn() === 2, 'New reports can coexist with an earlier decision from the same reporter.');
    $assert($database->query('SELECT status FROM content WHERE id = 3')->fetchColumn() === 'published', 'Inserting a report never changes content visibility.');
    if (!$mysql) { $assert($database->query('PRAGMA foreign_key_check')->fetchAll() === [], 'SQLite relationships remain valid.'); }
};

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $exercise(new PDO('sqlite::memory:'), false);
}
if (is_file($root . '/config.php') && in_array('mysql', PDO::getAvailableDrivers(), true)) {
    $config = require $root . '/config.php';
    $db = $config['database'];
    $database = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = 'tinycat_moderation_migration_test_' . bin2hex(random_bytes(6));
    $database->exec('CREATE DATABASE `' . $name . '`');
    try {
        $database->exec('USE `' . $name . '`');
        $exercise($database, true);
    } finally {
        $database->exec('DROP DATABASE `' . $name . '`');
    }
} else { echo "SKIP MySQL moderation migration: local MySQL configuration unavailable.\n"; }
echo "PASS independent post status migration ({$checks} checks)\n";
