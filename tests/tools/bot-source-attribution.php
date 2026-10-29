<?php
declare(strict_types=1);
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
if (!is_file($root . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP source attribution SQL: local MySQL unavailable.\n";
    exit(0);
}
$config = require $root . '/config.php';
$cfg = $config['database'];
if (!in_array($cfg['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
    echo "SKIP source attribution SQL: test database must be local.\n";
    exit(0);
}
$db = new PDO('mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306), $cfg['user'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$name = 'tinycat_attribution_sql_' . bin2hex(random_bytes(6));
$db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $db->exec('USE `' . $name . '`');
    $db->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role VARCHAR(20))');
    $db->exec('CREATE TABLE content (id BIGINT UNSIGNED PRIMARY KEY, author_id INT, body TEXT, published_at DATETIME)');
    $db->exec('CREATE TABLE bot_sources (id BIGINT UNSIGNED PRIMARY KEY, bot_user_id INT, name VARCHAR(120), feed_url VARCHAR(2048), enabled TINYINT)');
    $db->exec('CREATE TABLE links (id BIGINT PRIMARY KEY, normalized_url VARCHAR(2048))');
    $db->exec('CREATE TABLE content_links (content_id BIGINT, link_id BIGINT)');
    $db->exec("INSERT INTO users VALUES (1,'bot'),(2,'bot'),(3,'user')");
    $db->exec("INSERT INTO bot_sources VALUES (1,1,'One','https://www.example.com/rss',1),(2,1,'Two','https://news.example.net/rss',1),(3,1,'Shared A','https://shared.test/a',1),(4,1,'Shared B','https://www.shared.test/b',1),(5,2,'Other bot','https://example.com/rss',1),(6,1,'Steam A','https://steamcommunity.com/groups/group-a/rss/',1),(7,1,'Steam B','https://steamcommunity.com/groups/group-b/rss/',1)");
    $post = $db->prepare("INSERT INTO content VALUES (?,?,?,'2099-01-01 00:00:00')");
    foreach (range(1, 19) as $id) { $post->execute([$id, $id === 7 ? 2 : ($id === 8 ? 3 : 1), 'Fixture ' . $id]); }
    $urls = [1=>['https://WWW.EXAMPLE.COM:443/article'],2=>['https://shared.test/a'],3=>['https://example.com/a','https://news.example.net/b'],4=>['https://example.com/a','https://youtube.com/watch?v=x'],5=>['https://unmatched.test/a'],6=>[],7=>['https://example.com/a'],8=>['https://example.com/a'],9=>['https://example.com.evil.test/a'],10=>['https://EXAMPLE.COM./article'],11=>['https://example.com/a','https://www.example.com/b'],12=>['javascript:example.com'],13=>['https://unknown.test/a'],14=>['https://example.com@evil.test/a'],15=>['https://example.com/?next=https://news.example.net/a'],16=>['https://steamcommunity.com/groups/group-a/announcements/detail/1'],17=>['https://www.steamcommunity.com/groups/group-b/announcements/detail/2'],18=>['https://steamcommunity.com/url?next=/groups/group-a/announcements/detail/3'],19=>['https://steamcommunity.com/groups/group-ax/announcements/detail/4']];
    $link = $db->prepare('INSERT INTO links VALUES (?,?)'); $relation = $db->prepare('INSERT INTO content_links VALUES (?,?)'); $linkId = 0;
    foreach ($urls as $contentId => $items) { foreach ($items as $url) { $link->execute([++$linkId,$url]); $relation->execute([$contentId,$linkId]); } }
    $directory = $root . '/tools/sql/bot-source-attribution/';
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
    $candidates = $db->query((string) file_get_contents($directory . '02_unique_candidates.sql'))->fetchAll();
    $assert(array_column($candidates, 'content_id') === [1,10,11,15,7], 'Exact single-domain matches include normalized www/ports/case/trailing dots and exclude humans.');
    $assert((int) end($candidates)['source_id'] === 5, 'The same domain maps to the source owned by the correct bot.');
    $ambiguous = array_column($db->query((string) file_get_contents($directory . '03_review_ambiguous.sql'))->fetchAll(), 'match_status', 'content_id');
    $assert($ambiguous[2] === 'ambiguous_sources' && $ambiguous[3] === 'ambiguous_sources' && $ambiguous[4] === 'multiple_link_domains', 'Multiple feeds and multiple linked domains remain review-only.');
    $summary = $db->query((string) file_get_contents($directory . '01_summary.sql'))->fetchAll();
    $assert(array_sum(array_column($summary,'posts')) === 18, 'Audit covers every bot post exactly once.');
    $combined = $db->query((string) file_get_contents($directory . '10_combined_summary.sql'))->fetchAll();
    $combinedCounts = array_column($combined, 'posts', 'match_status');
    $assert((int) $combinedCounts['unique_single_domain'] === 5 && (int) $combinedCounts['unique_steam_group'] === 2 && array_sum($combinedCounts) === 18, 'Combined summary counts each post once and separates matching rules.');
    $collisions = $db->query((string) file_get_contents($directory . '06_feed_collisions.sql'))->fetchAll();
    $assert(array_column($collisions, 'domain') === ['shared.test','steamcommunity.com'], 'Domain collisions are scoped to a bot account.');
    $steam = $db->query((string) file_get_contents($directory . '07_steam_group_candidates.sql'))->fetchAll();
    $assert(array_column($steam,'content_id') === [16,17] && array_column($steam,'source_id') === [6,7], 'Steam group path rules distinguish feeds without matching URL queries or partial slugs.');
    $aliases = $db->query((string) file_get_contents($directory . '09_apokalypsa_aliases_to_review.sql'))->fetchAll();
    $assert($aliases === [], 'Production-specific alias proposals are disabled for other database names.');
    $migrate = require $root . '/migrations/20261029_003_feed_publication_queue.php'; $migrate($db);
    $db->exec('INSERT INTO bot_source_posts VALUES (1,2)');
    $statement = $db->query((string) file_get_contents($directory . '90_apply_unique_dry_run.sql'));
    $inserted = null;
    do { if ($statement->columnCount() > 0) { foreach ($statement->fetchAll() as $row) { if (array_key_exists('would_insert_relations',$row)) { $inserted = (int) $row['would_insert_relations']; } } } } while ($statement->nextRowset());
    $statement->closeCursor();
    $assert($inserted === 4, 'Dry run only inserts new unambiguous relations.');
    $assert($db->query('SELECT content_id,source_id FROM bot_source_posts')->fetchAll() === [['content_id'=>1,'source_id'=>2]], 'Default rollback preserves existing relations and leaves no writes behind.');
    $assert((int) $db->query("SELECT COUNT(*) FROM content WHERE published_at='2099-01-01 00:00:00'")->fetchColumn() === 19, 'Attribution does not alter publication times.');
    $statement = $db->query((string) file_get_contents($directory . '91_apply_steam_groups_dry_run.sql'));
    $steamInserted = null;
    do { if ($statement->columnCount() > 0) { foreach ($statement->fetchAll() as $row) { if (array_key_exists('would_insert_relations',$row)) { $steamInserted = (int) $row['would_insert_relations']; } } } } while ($statement->nextRowset());
    $statement->closeCursor();
    $assert($steamInserted === 2 && (int) $db->query('SELECT COUNT(*) FROM bot_source_posts')->fetchColumn() === 1, 'Steam dry run rolls back and preserves existing mappings.');
    echo "PASS bot source attribution SQL ($checks checks; isolated MySQL, all classifications and rollback)\n";
} finally { $db->exec('DROP DATABASE `' . $name . '`'); }
