<?php
declare(strict_types=1);
require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Site\Sitemap;
use TinyCat\Status\Export;
use TinyCat\Support\Config;
use TinyCat\Support\Xml;

(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['database' => ['driver' => 'sqlite'], 'app' => ['url' => 'https://example.test']]);
require dirname(__DIR__) . '/tools/performance/ProfileDatabase.php';
$db = new \TinyCat\Tools\Performance\ProfileDatabase('sqlite::memory:', '', '');
Connection::set($db);
// No reactions, comments, previews or session tables: exports must not load UI state.
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, status TEXT)',
    'CREATE TABLE content (id INTEGER PRIMARY KEY, author_id INTEGER, body TEXT, published_at TEXT)',
    'CREATE TABLE content_images (content_id INTEGER, path TEXT, bytes INTEGER)',
    'CREATE TABLE terms (id INTEGER PRIMARY KEY, name TEXT)',
    'CREATE TABLE content_tags (content_id INTEGER, term_id INTEGER)',
    "INSERT INTO users VALUES (1, 'public', 'active'), (2, 'hidden', 'inactive')",
    "INSERT INTO content VALUES (1, 1, 'first', '2020-01-01'), (2, 1, 'second', '2020-01-02'), (3, 2, 'hidden', '2020-01-03'), (4, 1, 'future', '2999-01-01')",
    "INSERT INTO terms VALUES (1, 'public'), (2, 'orphan'), (3, 'hidden'), (4, 'future')",
    'INSERT INTO content_tags VALUES (1, 1), (3, 3), (4, 4)',
] as $sql) { $db->exec($sql); }
$checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($why); } };
$db->queries = [];
$assert(array_column(Export::posts(), 'id') === [2, 1], 'Public exports exclude inactive authors and future publications.');
$assert(count($db->queries) === 2 && !str_contains(implode(' ', array_column($db->queries, 'sql')), 'COUNT('), 'Public exports use two bounded queries without per-post reaction or comment counts.');
$assert(count(Export::posts(1)) === 1 && Export::posts(100, 2) === [], 'Limits and author filtering are applied in SQL.');
$assert(array_column(Export::posts(50, null, 1), 'id') === [1], 'Tag exports use the requested public tag only.');
$assert(Sitemap::count('tags') === 1 && array_column(Sitemap::entries('tags', 1000, 0), 'url') === ['/tag/public'], 'Sitemap counts and entries exclude orphan, hidden-only and future-only tags.');
$assert(Sitemap::count('status') === 2 && count(Sitemap::entries('status', 1000, 0)) === 2, 'Sitemap and export visibility agree.');
$assert(Sitemap::count('pages') === 2 && Sitemap::entries('pages', 1, 1)[0]['url'] === '/privacy', 'Public static pages share count and pagination rules.');
$assert(Sitemap::entries('status', 1000, 0)[0]['last_modified'] === null, 'Publication time is not presented as a modification timestamp.');
$assert(Xml::escape("A\x01<&B") === 'A&lt;&amp;B', 'XML escapes entities and removes forbidden control characters.');
$xml = simplexml_load_string('<value>' . Xml::cdata('a]]>b') . '</value>');
$assert($xml !== false && (string) $xml === 'a]]>b', 'CDATA terminators remain valid and round-trip without changes.');
$pages = Sitemap::pageCounts();
$db->exec("UPDATE users SET status = 'inactive' WHERE id = 1");
$assert(Sitemap::pageCounts() === $pages && Sitemap::entries('status', 1000, 0) === [] && Export::posts() === [], 'A cached sitemap directory never preserves content after its author becomes hidden.');
echo "PASS public export visibility and minimal data queries ({$checks} checks)\n";
