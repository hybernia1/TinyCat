<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

use TinyCat\Social\Bots\DuplicateCleanup;
use TinyCat\Database\Connection;
use TinyCat\Social\Posts\Repository;

(new ReflectionProperty(TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$exercise = static function (TinyCatTestLockPDO $db) use ($assert): void {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Connection::set($db);


    foreach ([
        'CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,username VARCHAR(40),role VARCHAR(20),status VARCHAR(20))',
        'CREATE TABLE social_posts (id BIGINT UNSIGNED PRIMARY KEY,author_id INT UNSIGNED,body VARCHAR(2000),published_at DATETIME,status VARCHAR(20), INDEX content_feed_index (published_at,id), INDEX content_sidebar_index (published_at,author_id,id), INDEX content_author_index (author_id,published_at,id))',
        'CREATE TABLE social_post_likes (content_id BIGINT UNSIGNED,user_id INT UNSIGNED,PRIMARY KEY(content_id,user_id),FOREIGN KEY(content_id) REFERENCES social_posts(id) ON DELETE CASCADE)',
        'CREATE TABLE social_comments (id BIGINT UNSIGNED PRIMARY KEY,content_id BIGINT UNSIGNED,parent_id BIGINT UNSIGNED NULL,body VARCHAR(2000),comments_diff TEXT,FOREIGN KEY(content_id) REFERENCES social_posts(id) ON DELETE CASCADE,FOREIGN KEY(parent_id) REFERENCES social_comments(id) ON DELETE CASCADE)',
        'CREATE TABLE social_comment_likes (comment_id BIGINT UNSIGNED,user_id INT UNSIGNED,FOREIGN KEY(comment_id) REFERENCES social_comments(id) ON DELETE CASCADE)',
        'CREATE TABLE social_reports (id BIGINT UNSIGNED PRIMARY KEY,content_id BIGINT UNSIGNED,note TEXT,FOREIGN KEY(content_id) REFERENCES social_posts(id) ON DELETE CASCADE)',
        'CREATE TABLE social_terms (id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(80))',
        'CREATE TABLE social_links (id BIGINT UNSIGNED PRIMARY KEY,normalized_url VARCHAR(2048),image_url VARCHAR(2048))',
        'CREATE TABLE social_post_tags (content_id BIGINT UNSIGNED,term_id BIGINT UNSIGNED,PRIMARY KEY(content_id,term_id),FOREIGN KEY(content_id) REFERENCES social_posts(id) ON DELETE CASCADE)',
        'CREATE TABLE social_post_links (content_id BIGINT UNSIGNED,link_id BIGINT UNSIGNED,PRIMARY KEY(content_id,link_id),FOREIGN KEY(content_id) REFERENCES social_posts(id) ON DELETE CASCADE)',
        'CREATE TABLE social_post_images (content_id BIGINT UNSIGNED PRIMARY KEY,path VARCHAR(255),width INT,height INT,bytes INT,FOREIGN KEY(content_id) REFERENCES social_posts(id) ON DELETE CASCADE)',
        'CREATE TABLE notifications (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,actor_id INT UNSIGNED NULL,subject_id BIGINT UNSIGNED,payload_json LONGTEXT NULL,type VARCHAR(40),notification_key VARCHAR(190),read_at DATETIME NULL,created_at DATETIME,updated_at DATETIME,UNIQUE(user_id,notification_key))',
        'CREATE TABLE bot_source_posts (content_id BIGINT UNSIGNED PRIMARY KEY,source_id BIGINT UNSIGNED,FOREIGN KEY(content_id) REFERENCES social_posts(id) ON DELETE CASCADE)',
        'CREATE TABLE bot_feed_history (bot_user_id INT UNSIGNED,feed_hash CHAR(64),item_hash CHAR(64),created_at DATETIME)',
    ] as $sql) { $db->exec($sql); }
    $db->exec("INSERT INTO users VALUES (1,'bot','bot','active'),(2,'other','bot','active'),(3,'human','user','active'),(4,'blocked','bot','ban'),(5,'reader','user','active')");
    $insert = $db->prepare('INSERT INTO social_posts (id, author_id, body, published_at, status) VALUES (?,?,?,?,?)');
    for ($id = 1; $id <= 18; $id++) {
        $author = match ($id) { 4 => 3, 5 => 2, 17, 18 => 4, default => 1 };
        $insert->execute([$id, $author, $id === 6 ? 'same article' : 'Same article', $id === 8 ? '2099-01-01 00:00:00' : ($id === 1 ? '2000-02-01 00:00:00' : '2000-01-01 00:00:00'), $id === 7 ? 'hidden' : 'published']);
    }
    $db->exec("INSERT INTO social_terms VALUES (1,'one'),(2,'two')");
    $db->exec("INSERT INTO social_links VALUES (1,'https://article.example/one',NULL),(2,'https://article.example/two',NULL)");
    for ($id = 1; $id <= 18; $id++) {
        $db->exec('INSERT INTO social_post_links VALUES (' . $id . ',' . (in_array($id, [9, 10], true) ? 2 : 1) . ')');
        $db->exec('INSERT INTO social_post_tags VALUES (' . $id . ',' . (in_array($id, [15, 16], true) ? 2 : 1) . ')');
    }
    $db->exec("INSERT INTO social_post_images VALUES (11,'2026/11/shared.webp',20,20,100),(12,'2026/11/different.webp',20,20,100),(13,'2026/11/shared.webp',20,20,100),(14,'2026/11/shared.webp',40,20,100)");
    $db->exec('INSERT INTO social_post_likes VALUES (1,3),(2,3),(2,4),(3,5)');
    $db->exec("INSERT INTO social_comments VALUES (101,2,NULL,'Parent','history'),(102,2,101,'Reply','reply history'),(103,3,NULL,'Other','history')");
    $db->exec('INSERT INTO social_comment_likes VALUES (102,3)');
    $db->exec("INSERT INTO social_reports VALUES (1,2,'keep report'),(2,3,'keep another report')");
    $notify = $db->prepare('INSERT INTO notifications VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        [1,3,4,2,null,'content_like','content_like:99:4',null,'2000-01-01','2000-01-02'],
        [2,3,4,1,null,'content_like','content_like:1:4','2001-01-01','2000-02-01','2001-01-01'],
        [3,3,5,2,102,'comment_mention','comment_mention:2:102:5',null,'2000-01-01','2000-01-01'],
        [4,3,4,3,103,'comment_like','comment_like:103:4',null,'2000-01-01','2000-01-01'],
        [5,5,4,3,null,'content_mention','content_mention:3:0:4',null,'2000-01-01','2000-01-01'],
        [6,3,null,2,null,'report_resolved','report_resolved:2',null,'2000-01-01','2000-01-01'],
        [7,3,5,3,103,'content_comment','content_comment:3:103',null,'2000-01-01','2000-01-01'],
    ] as $row) { $row[3] = in_array($row[5], ['comment_like', 'comment_mention', 'content_comment'], true) ? $row[4] : $row[3]; $row[4] = null; $notify->execute($row); }
    $db->exec('INSERT INTO bot_source_posts VALUES (1,1),(2,999),(3,1)');
    $db->exec("INSERT INTO bot_feed_history VALUES (1,'feed','guid','2000-01-01')");
    // Upgrade the previous local, unreleased table to the promised two-column structure.
    $db->exec('CREATE TABLE social_post_redirects (old_id BIGINT UNSIGNED PRIMARY KEY,content_id BIGINT UNSIGNED,author_id INT UNSIGNED NOT NULL)');
    $db->exec('CREATE INDEX content_redirects_target_index ON social_post_redirects (content_id)');
    $db->exec('INSERT INTO social_post_redirects VALUES (99,2,1)');
    $migration = require __DIR__ . '/../../../migrations/20261102_001_compact_content_redirects.php';
    $db->exec('ALTER TABLE social_posts RENAME TO content');
    $db->exec('ALTER TABLE social_post_redirects RENAME TO content_redirects');
    $migration($db); $migration($db);
    $db->exec('ALTER TABLE content RENAME TO social_posts');
    $db->exec('ALTER TABLE content_redirects RENAME TO social_post_redirects');
    $columns = $db->query('SHOW COLUMNS FROM social_post_redirects')->fetchAll(PDO::FETCH_COLUMN);
    $assert($columns === ['old_id', 'content_id'], 'Redirect storage has exactly the two requested columns.');
    $pairs = DuplicateCleanup::candidates(100);
    $assert(array_map(static fn (array $row): int => (int) $row['id'], $pairs) === [2,3,10,13,16], 'Only exact same-bot public duplicates match; users, other bots, case differences, hidden/future posts and differing metadata remain.');
    $assert((int) $pairs[2]['keeper_id'] === 9 && (int) $pairs[4]['keeper_id'] === 15, 'The oldest fully matching post wins even when a lower-ID post has different links or tags.');
    $assert((int) $db->query('SELECT COUNT(*) FROM social_posts')->fetchColumn() === 18, 'Preview is read-only.');
    $first = DuplicateCleanup::run(1);
    $assert($first['changed'] === 1 && $first['has_more'], 'Cleanup respects its batch limit and reports remaining work.');
    $assert($first['inspected'] === 2, 'Inspection counts the candidates visited through the merge limit, not the configured batch.');
    $assert(Repository::redirectTarget(2) === 1 && Repository::redirectTarget(99) === 1, 'New and earlier redirects point directly at the surviving post.');
    $trigger = "CREATE TRIGGER fail_duplicate BEFORE DELETE ON social_posts FOR EACH ROW BEGIN IF OLD.id=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rollback'; END IF; END";
    $db->exec($trigger);
    $failed = DuplicateCleanup::run(1);
    $assert(isset($failed['error']) && $failed['changed'] === 0 && (int) $db->query('SELECT content_id FROM social_comments WHERE id=103')->fetchColumn() === 3, 'A late failure rolls back every relation move for that post.');
    $assert($db->query('SELECT 1 FROM social_post_redirects WHERE old_id=3')->fetchColumn() === false, 'A failed merge leaves no alias.');
    $db->exec('DROP TRIGGER fail_duplicate');
    $rest = DuplicateCleanup::run(500);
    $assert(!isset($rest['error']) && $rest['changed'] === 4 && !$rest['has_more'], 'The next run finishes the remaining safe duplicates.');
    $assert((int) $db->query('SELECT COUNT(*) FROM social_post_likes WHERE content_id=1')->fetchColumn() === 3, 'Reactions are combined once per user.');
    $assert((int) $db->query('SELECT COUNT(*) FROM social_comments WHERE content_id=1')->fetchColumn() === 3
        && (int) $db->query('SELECT parent_id FROM social_comments WHERE id=102')->fetchColumn() === 101
        && $db->query('SELECT comments_diff FROM social_comments WHERE id=102')->fetchColumn() === 'reply history'
        && (int) $db->query('SELECT COUNT(*) FROM social_comment_likes')->fetchColumn() === 1, 'Comment IDs, replies, history and likes survive.');
    $assert((int) $db->query('SELECT COUNT(*) FROM social_reports WHERE content_id=1')->fetchColumn() === 2, 'All report history survives.');
    $assert((int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 6
        && $db->query('SELECT read_at FROM notifications WHERE id=2')->fetchColumn() === null, 'Notification collisions collapse while preserving unread state.');
    foreach ([3 => 'comment_mention:1:102:5',4 => 'comment_like:103:4',5 => 'content_mention:1:0:4',6 => 'report_resolved:2',7 => 'content_comment:1:103'] as $id => $key) {
        $row = $db->query('SELECT * FROM notifications WHERE id=' . $id)->fetch();
        $assert((int) $row['subject_id'] === (in_array($id, [3,4,7], true) ? ($id === 3 ? 102 : 103) : 1) && $row['notification_key'] === $key, 'Content-derived keys move; comment and report identities stay unchanged.');
    }
    $assert($db->query('SELECT published_at FROM social_posts WHERE id=1')->fetchColumn() === '2000-02-01 00:00:00', 'The keeper retains its original publication date.');
    $assert((int) $db->query('SELECT COUNT(*) FROM bot_feed_history')->fetchColumn() === 1, 'Cleanup does not change RSS GUID history.');
    $assert($db->query('SELECT path FROM social_post_images WHERE content_id=11')->fetchColumn() === '2026/11/shared.webp', 'The shared attachment stays attached to the keeper.');
    $again = \TinyCat\Core\Tasks\Cleanup\Task::runTask('duplicate_bot_posts', 500);
    $assert($again['changed'] === 0 && $again['done'], 'The existing cleanup task dispatches this idempotent subtask.');
    $seed = $db->prepare("INSERT INTO social_posts (id,author_id,body,published_at,status) VALUES (?,1,?,'2000-01-01','published')");
    for ($id = 1000; $id < 1650; $id++) { $seed->execute([$id, 'unique-' . $id]); }
    $seed->execute([2000, 'unique-1000']);
    $window = DuplicateCleanup::run(1);
    $assert($window['changed'] === 0 && $window['has_more'], 'A bounded unique window advances rather than declaring the entire scan finished.');
    $assert($window['inspected'] === 256, 'A unique inspection window reports real progress even without a merge.');
    for ($round = 0; $round < 10 && $window['has_more']; $round++) { $window = DuplicateCleanup::run(1); }
    $assert(!$window['has_more'] && Repository::redirectTarget(2000) === 1000, 'Later duplicate candidates reach their turn and find keepers outside the current window.');

    $collisionA = 'collision-1fb34678cd8a644bac118672';
    $collisionB = 'collision-0c5cb8de61d1eef2b0ef6289';
    $collision = $db->prepare('SELECT CRC32(?)=CRC32(?)'); $collision->execute([$collisionA, $collisionB]);
    $assert((int) $collision->fetchColumn() === 1 && $collisionA !== $collisionB, 'The fixture exercises different byte strings with an identical CRC32.');
    $prefix = str_repeat('shared-prefix-', 20);
    foreach ([3000 => $collisionA, 3001 => $collisionB, 3002 => $collisionB,
        3100 => $prefix . 'first', 3101 => $prefix . 'second', 3102 => $prefix . 'first',
        3200 => 'Příliš žluťoučký 🐈', 3201 => 'Příliš žluťoučký 🐈', 3202 => 'PŘÍLIŠ žluťoučký 🐈',
        3300 => 'trailing ', 3301 => 'trailing', 3302 => 'trailing ', 3400 => '   ',
        3500 => 'Příliš žluťoučký 🐈', 3501 => 'visibility-keeper', 3502 => 'visibility-keeper', 3503 => 'visibility-keeper', 3504 => 'visibility-keeper',
        3600 => 'attachment-case', 3601 => 'attachment-case'] as $id => $body) { $seed->execute([$id, $body]); }
    $db->exec("UPDATE social_posts SET author_id=2 WHERE id=3500");
    $db->exec("UPDATE social_posts SET status='hidden' WHERE id=3501");
    $db->exec("UPDATE social_posts SET published_at='2099-01-01' WHERE id=3502");
    $db->exec("INSERT INTO social_post_images VALUES (3600,'2026/11/Case.webp',20,20,100),(3601,'2026/11/case.webp',20,20,100)");
    $scan = new ReflectionMethod(DuplicateCleanup::class, 'scan');
    $edgeCases = $scan->invoke(null, 500, 2999);
    $expected = [['id' => 3002, 'keeper_id' => 3001], ['id' => 3102, 'keeper_id' => 3100], ['id' => 3201, 'keeper_id' => 3200], ['id' => 3302, 'keeper_id' => 3300], ['id' => 3504, 'keeper_id' => 3503]];
    $assert($edgeCases['pairs'] === $expected, 'CRC collisions, long prefixes, Unicode, case, trailing spaces, author visibility and attachment path bytes retain exact keeper selection.');
    $crossCollision = $scan->invoke(null, 500, 3001);
    $assert($crossCollision['pairs'][0] === ['id' => 3002, 'keeper_id' => 3001], 'A historical hash collision does not replace the lowest fully matching keeper outside the window.');
    $empty = $scan->invoke(null, 500, 999999);
    $assert($empty['pairs'] === [] && $empty['inspected'] === 0 && !$empty['has_more'], 'An empty window finishes without a historical matching pass.');
    $merged = DuplicateCleanup::run(500);
    $assert($merged['changed'] === 5 && !$merged['has_more'], 'Only the exact matching edge cases merge through the normal checkpointed runner.');
    $assert($db->query('SELECT 1 FROM social_posts WHERE id=3000')->fetchColumn() !== false && Repository::redirectTarget(3002) === 3001, 'Hash collisions never delete the differing text or select it as a keeper.');
    for ($id = 4000; $id < 4060; $id++) { $seed->execute([$id, 'dense-' . intdiv($id - 4000, 2)]); }
    $queries = [];
    $db->beforePrepare = static function (string $sql) use (&$queries): void { $queries[] = $sql; };
    try { $dense = $scan->invoke(null, 500, 3999); }
    finally { $db->beforePrepare = null; }
    $assert(count($dense['pairs']) === 30 && count($queries) <= 2, 'A dense duplicate window skips the historical prefilter and stays within two queries.');
    $db->exec("UPDATE social_posts SET status='hidden' WHERE id=1");
    $assert(Repository::redirectTarget(2) === null, 'Aliases cannot reveal a subsequently moderated post.');
    $db->exec('DELETE FROM social_posts WHERE id=1');
    $assert($db->query('SELECT 1 FROM social_post_redirects WHERE old_id=2')->fetchColumn() === false, 'Deleting a keeper removes its aliases.');
};
$exercise(\tinycat_test_database_as(TinyCatTestLockPDO::class));
echo "PASS bot duplicate housekeeping ($checks checks)\n";
