<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
$migrate = require $root . '/migrations/20261101_001_content_status.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$exercise = static function (PDO $database) use ($migrate, $assert): void {

    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $id = 'BIGINT UNSIGNED';
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
    $database->exec('CREATE TABLE content_comments (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, content_id ' . $id . ', body TEXT, FOREIGN KEY(content_id) REFERENCES content(id) ON DELETE CASCADE)');
    $database->exec("INSERT INTO content_comments VALUES (1, 2, 'Preserved discussion')");
    $reports = $database->query('SELECT * FROM content_reports ORDER BY id')->fetchAll();
    $migrate($database);
    $assert($database->query('SELECT status FROM content ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['published', 'hidden', 'published'], 'Only explicitly hidden legacy posts become hidden.');
    $assert($database->query('SELECT * FROM content_reports ORDER BY id')->fetchAll() === $reports, 'Report IDs, notes and decisions survive the migration.');
    $assert($database->query('SELECT body FROM content_comments WHERE id = 1')->fetchColumn() === 'Preserved discussion', 'Discussion survives the schema change.');
    $columns = $database->query('SHOW COLUMNS FROM content')->fetchAll();
    $names = array_column($columns, 'Field');
    $assert(!array_intersect($names, ['edit_locked_at', 'edit_locked_by', 'edit_lock_reason']), 'All three edit-lock columns are removed.');
    $database->exec("UPDATE content SET status = 'published' WHERE id = 2");
    $migrate($database);
    $assert($database->query('SELECT status FROM content WHERE id = 2')->fetchColumn() === 'published', 'A repeated migration preserves later administrative publication.');
    $database->exec("INSERT INTO content (id, author_id, body, published_at) VALUES (4, 1, 'New post', '2020-03-01')");
    $assert($database->query('SELECT status FROM content WHERE id = 4')->fetchColumn() === 'published', 'New content defaults to published independently of reports.');
    $database->exec("INSERT INTO content_reports (id, content_id, reporter_id, reason, status, created_at) VALUES (3, 3, 2, 'spam', 'open', '2020-03-01')");
    $assert((int) $database->query('SELECT COUNT(*) FROM content_reports WHERE content_id = 3 AND reporter_id = 2')->fetchColumn() === 2, 'New reports can coexist with an earlier decision from the same reporter.');
    $assert($database->query('SELECT status FROM content WHERE id = 3')->fetchColumn() === 'published', 'Inserting a report never changes content visibility.');

};

$exercise(tinycat_test_database());

// Upgrade the last published Social schemas without losing posts or report history.
$database = tinycat_test_database();
$database->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
$database->exec('INSERT INTO users VALUES (1), (2)');
foreach (['publishing/fresh-v4.php', 'moderation/fresh-v1.php'] as $file) {
    $install = require $root . '/App/Modules/Social/migrations/' . $file;
    $install($database);
}
$database->exec("INSERT INTO social_posts (id, body, author_id, published_at) VALUES (7, 'Preserved post', 1, '2026-01-01')");
$database->exec("INSERT INTO content_reports (id, content_id, reporter_id, reason, note, status, reviewed_by, action_note) VALUES (9, 7, 2, 'spam', 'Evidence', 'resolved', 1, 'Decision')");
$reports = $database->query('SELECT * FROM content_reports')->fetchAll(PDO::FETCH_ASSOC);
$postsOnly = require $root . '/App/Modules/Social/migrations/publishing/005_posts_only.php';
$renameReports = require $root . '/App/Modules/Social/migrations/moderation/002_social_reports.php';
$database->exec("INSERT INTO social_posts (id, kind, body, author_id, published_at) VALUES (8, 'unexpected', 'Reject migration', 1, '2026-01-01')");
$rejected = false;
try { $postsOnly($database); } catch (RuntimeException) { $rejected = true; }
$assert($rejected && in_array('kind', $database->query('SHOW COLUMNS FROM social_posts')->fetchAll(PDO::FETCH_COLUMN), true), 'Unexpected kinds reject migration before removing the discriminator.');
$database->exec('DELETE FROM social_posts WHERE id = 8');
foreach ([1, 2] as $pass) { $postsOnly($database); $renameReports($database); }
$assert(!in_array('kind', $database->query('SHOW COLUMNS FROM social_posts')->fetchAll(PDO::FETCH_COLUMN), true), 'The post discriminator is removed and migration can resume.');
$assert($database->query('SELECT * FROM social_reports')->fetchAll(PDO::FETCH_ASSOC) === $reports, 'Renaming reports preserves IDs, evidence, decisions and timestamps.');
$assert($database->query('SELECT body FROM social_posts WHERE id = 7')->fetchColumn() === 'Preserved post', 'Post bodies and IDs survive.');
$database->exec('DELETE FROM users WHERE id = 2');
$assert($database->query('SELECT reporter_id FROM social_reports WHERE id = 9')->fetchColumn() === null, 'Renamed report foreign keys still clear removed reporters.');
$database->exec('DELETE FROM social_posts WHERE id = 7');
$assert((int) $database->query('SELECT COUNT(*) FROM social_reports')->fetchColumn() === 0, 'Renamed reports retain their post cascade.');
$fresh = tinycat_test_database();
$fresh->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
foreach (['publishing/fresh-v5.php', 'moderation/fresh-v2.php'] as $file) {
    $install = require $root . '/App/Modules/Social/migrations/' . $file;
    $install($fresh);
}
$assert($fresh->query('SHOW COLUMNS FROM social_posts')->fetchAll(PDO::FETCH_ASSOC) === $database->query('SHOW COLUMNS FROM social_posts')->fetchAll(PDO::FETCH_ASSOC), 'Fresh and upgraded post columns match.');
$assert($fresh->query('SHOW COLUMNS FROM social_reports')->fetchAll(PDO::FETCH_ASSOC) === $database->query('SHOW COLUMNS FROM social_reports')->fetchAll(PDO::FETCH_ASSOC), 'Fresh and upgraded report columns match.');

echo "PASS independent post status migration ({$checks} checks)\n";
