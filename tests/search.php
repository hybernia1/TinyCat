<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Search\Queries;

$mysql = in_array('--mysql', $argv, true);
$server = null;
$created = false;
$databaseName = 'tinycat_search_test_' . bin2hex(random_bytes(6));
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException($message); }
};
try {
    if ($mysql) {
        $config = require (getenv('TINYCAT_TEST_MYSQL_CONFIG') ?: dirname(__DIR__) . '/config.php');
        $db = $config['database'];
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';charset=utf8mb4';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        $server = new PDO($dsn, $db['user'], $db['password'], $options);
        $server->exec('CREATE DATABASE ' . $databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        $pdo = new PDO($dsn . ';dbname=' . $databaseName, $db['user'], $db['password'], $options);
    } else {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { echo "SKIP search: pdo_sqlite unavailable.\n"; exit(0); }
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    Connection::set($pdo);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(80), bio TEXT, status VARCHAR(16), avatar_exists INTEGER DEFAULT 0, avatar_appearance TEXT, updated_at DATETIME)');
    $pdo->exec('CREATE TABLE terms (id INTEGER PRIMARY KEY, name VARCHAR(80))');
    $pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, author_id INTEGER, body TEXT, published_at DATETIME' . ($mysql ? ', FULLTEXT KEY content_body_fulltext (body)' : '') . ')');
    $pdo->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, normalized_url TEXT, title TEXT, description TEXT' . ($mysql ? ', FULLTEXT KEY links_search_fulltext (normalized_url, title, description)' : '') . ')');
    $pdo->exec('CREATE TABLE content_tags (content_id INTEGER, term_id INTEGER)');
    $pdo->exec('CREATE TABLE content_links (content_id INTEGER, link_id INTEGER)');
    $pdo->exec("INSERT INTO users (id, username, status, bio) VALUES (1, 'nebula', 'active', 'secret biography'), (2, 'xnebula', 'active', ''), (3, 'nebula-more', 'active', ''), (4, 'nebula-hidden', 'blocked', ''), (5, 'name_under', 'active', ''), (6, 'nameXunder', 'active', ''), (7, '100%real', 'active', '')");
    $pdo->exec("INSERT INTO terms (id, name) VALUES (1, 'nebula'), (2, 'xnebula'), (3, 'nebula-more')");
    $insert = $pdo->prepare('INSERT INTO content (id, author_id, body, published_at) VALUES (?, ?, ?, ?)');
    $pdo->beginTransaction();
    for ($i = 1; $i <= 3100; $i++) { $insert->execute([$i, 1, 'ordinary unrelated text', '2020-01-01 00:00:00']); }
    for ($i = 1; $i <= 25; $i++) { $insert->execute([10000 + $i, 1, 'quartz bright nebula', '2021-01-01 00:00:00']); }
    $insert->execute([20000, 1, 'nebula ancient quartz', '2000-01-01 00:00:00']);
    $insert->execute([20001, 4, 'quartz nebula', '2022-01-01 00:00:00']);
    $insert->execute([20002, 1, 'quartz nebula', '2099-01-01 00:00:00']);
    $insert->execute([20003, 1, 'unrelated link carrier', '2023-01-01 00:00:00']);
    $insert->execute([20005, 1, 'Symbols "<>" and comparison < >', '2023-01-01 00:00:00']);
    $insert->execute([20004, 1, 'literal 100% underscore_value marker', '2023-01-01 00:00:00']);
    $pdo->exec("INSERT INTO links (id, normalized_url, title, description) VALUES (1, 'https://example.test/one', 'quartz', 'bright nebula'), (2, 'https://example.test/two', 'quartz nebula', '')");
    $pdo->exec('INSERT INTO content_links (content_id, link_id) VALUES (20003, 1), (20003, 2), (10025, 1)');
    $pdo->exec('INSERT INTO content_tags (content_id, term_id) VALUES (10001, 1), (20001, 1), (20002, 1)');
    $pdo->commit();
    \TinyCat\Users\Authentication::reset(null, true);

    $first = Queries::collection('  nebula   quartz  ');
    parse_str((string) parse_url($first['next_url'], PHP_URL_QUERY), $cursor);
    $insert->execute([30000, 1, 'quartz nebula inserted between requests', '2025-01-01 00:00:00']);
    $second = Queries::collection('nebula quartz', 'content', $cursor);
    $ids = array_column([...$first['items'], ...$second['items']], 'id');
    $assert(str_contains($first['items'][0]['excerpt'], 'nebula'), 'Link-only matches include a matching link excerpt.');
    $assert($first['query'] === 'nebula quartz', 'Query whitespace is normalized.');
    $assert(count($first['items']) === 20 && !$first['done'] && count($second['items']) === 7 && $second['done'], 'Pagination includes all 27 matches without duplicates.');
    $assert(!in_array(30000, $ids, true), 'New posts before the cursor cannot shift or duplicate the continuation.');
    $assert($ids[0] === 20003 && $ids[1] === 10025 && end($ids) === 20000, 'Body and link matches share newest-first ordering, including the oldest history.');
    $assert(count(array_unique($ids)) === count($ids), 'Matching body and multiple links produce one post.');
    $assert(!in_array(20001, $ids, true) && !in_array(20002, $ids, true), 'Inactive authors and future-dated records stay hidden.');
    foreach (['quartz"', '"quartz"', '<quartz>', '"<quartz>"'] as $decorated) {
        $assert(array_column(Queries::collection($decorated)['items'], 'id') === array_column(Queries::collection('quartz')['items'], 'id'), 'Quotes and angle brackets do not block word matches.');
    }
    $assert(array_column(Queries::collection('"<>"')['items'], 'id') === [20005], 'Punctuation-only queries are searched literally.');
    $assert(Queries::collection('nebula missingword')['items'] === [], 'All words must match.');
    $assert(Queries::collection('ne')['valid_query'] === false, 'Posts require three characters.');
    $assert(Queries::collection(str_repeat('a', 81))['items'] === [], 'Overlong queries never run search SQL.');
    $assert(Queries::collection('biography', 'users')['items'] === [], 'Profiles do not search bios.');
    $assert(array_column(Queries::collection('nebula', 'users')['items'], 'title') === ['nebula', 'nebula-more', 'xnebula'], 'Profiles rank exact, prefix and contains; inactive profiles are hidden.');
    $assert(array_column(Queries::collection('nebula', 'tags')['items'], 'title') === ['#nebula', '#nebula-more', '#xnebula'], 'Tag ranking matches profile ranking.');
    foreach (['users', 'tags'] as $type) {
        $batch = Queries::collection('nebula', $type, [], 2);
        parse_str((string) parse_url($batch['next_url'], PHP_URL_QUERY), $cursor);
        $rest = Queries::collection('nebula', $type, $cursor, 2);
        $assert(count($batch['items']) === 2 && count($rest['items']) === 1 && $rest['done'] && $batch['items'][1]['id'] !== $rest['items'][0]['id'], 'Rank/name/ID cursors preserve entity ranking without offsets.');
    }
    $assert(count(Queries::collection('name_', 'users')['items']) === 1, 'Underscores cannot broaden a profile search.');
    $assert(count(Queries::public_search_suggestion_users('100%', 5)) === 1, 'Percent signs are literal in autocomplete.');
    $assert(Queries::public_search_suggestion_users('name%', 5) === [], 'Wildcard input cannot list unrelated suggestions.');
    $assert(count(Queries::collection('100%')['items']) === 1, 'Post punctuation is literal.');
    $assert(Queries::collection('%nebula', 'tags')['items'] === [], 'Percent signs do not turn into a valid tag query.');
    $assert(Queries::public_search_suggestions('nebula')['content'] === [], 'Autocomplete never queries posts.');
    $assert(Queries::public_search_suggestion_tags('nebula', 5)[0]['posts_count'] === 1, 'Guests count only published posts from active authors.');
    \TinyCat\Users\Authentication::reset(['id' => 1, 'role' => 'admin'], true);
    $assert(Queries::public_search_suggestion_tags('nebula', 5)[0]['posts_count'] === 1, 'Admins use the same published tag counts as public readers.');
    $assert(Queries::collection('nebula', 'tags')['items'][0]['posts_count'] === 1, 'Tag results share autocomplete counts.');
    \TinyCat\Users\Authentication::reset(['id' => 1, 'role' => 'user'], true);
    $assert(Queries::public_search_suggestion_tags('nebula', 5)[0]['posts_count'] === 1, 'Authors use the same published tag counts as public readers.');
    \TinyCat\Users\Authentication::reset(['id' => 2, 'role' => 'user'], true);
    $assert(Queries::public_search_suggestion_tags('nebula', 5)[0]['posts_count'] === 1, "All viewers use the same published tag counts.");
    \TinyCat\Users\Authentication::reset(null, true);
    $pdo->exec("INSERT INTO links (id, normalized_url, title, description) VALUES (3, 'https://example.test/a', 'unionboundary alpha', ''), (4, 'https://example.test/z', 'unionboundary zeta', '')");
    for ($i = 1; $i <= 80; $i++) {
        $id = 40000 + $i;
        $insert->execute([$id, 1, $i % 3 === 1 ? 'unrelated carrier' : 'unionboundary body', '2024-01-01 00:00:00']);
        if ($i % 3 !== 0) { $pdo->exec('INSERT INTO content_links (content_id, link_id) VALUES (' . $id . ', 3), (' . $id . ', 4)'); }
    }
    $seen = []; $at = ''; $lastId = 0;
    do {
        $batch = Queries::public_search_content_rows('unionboundary', 7, $at, $lastId);
        foreach ($batch as $row) {
            $seen[] = (int) $row['id'];
            if (((int) $row['id'] - 40000) % 3 !== 0) { $assert(str_contains((string) $row['link_excerpt'], 'zeta'), 'Limited sources preserve MAX matching-link excerpt, including body/link overlap.'); }
        }
        if ($batch !== []) { $last = $batch[array_key_last($batch)]; $at = $last['published_at']; $lastId = (int) $last['id']; }
    } while ($batch !== [] && count($seen) <= 80);
    $assert($seen === range(40080, 40001), 'Per-source limits preserve every tied-time result across pages without duplicates or omissions.');
    $assert(array_column(Queries::public_search_content_rows('unionboundary', 7, 'invalid', 2), 'id') === range(40080, 40074), 'Invalid content cursor uses the first page.');
    $assert(count(Queries::public_search_content_rows('unionboundary', 0)) === 1 && count(Queries::public_search_content_rows('unionboundary', 100)) === 51, 'Bounded source limits retain public limit normalization.');
    \TinyCat\Http\Session::start();
    $_SESSION['_tinycat_search_guard'] = ['count' => 7];
    \TinyCat\Search\Guard::suggestions();
    $assert($_SESSION['_tinycat_search_guard'] === ['count' => 7] && $_SESSION['_tinycat_suggestion_guard']['count'] === 1, 'Autocomplete uses a separate search budget.');
    echo 'PASS search semantics (' . ($mysql ? 'MySQL/MariaDB' : 'SQLite') . ", {$checks} checks)\n";
} finally {
    if ($server instanceof PDO) { Connection::set($server); }
    $pdo = null;
    if ($server instanceof PDO && $created) { $server->exec('DROP DATABASE IF EXISTS ' . $databaseName); }
}
