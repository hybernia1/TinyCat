<?php
declare(strict_types=1);
require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Social\Presentation\Sitemap;
use TinyCat\Social\Posts\Export;
use TinyCat\Support\Config;
use TinyCat\Support\Xml;

(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['database' => ['driver' => 'sqlite'], 'app' => ['url' => 'https://example.test']]);
require __DIR__ . '/../../../tools/performance/lib/ProfileDatabase.php';
$db = new \TinyCat\Tools\Performance\ProfileDatabase('sqlite::memory:', '', '');
Connection::set($db);
// No reactions, comments, previews or session tables: exports must not load UI state.
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, status TEXT)',
    'CREATE TABLE social_posts (kind VARCHAR(40) NOT NULL DEFAULT \'post\', id INTEGER PRIMARY KEY, author_id INTEGER, body TEXT, published_at TEXT)',
    'CREATE TABLE social_post_images (content_id INTEGER, path TEXT, bytes INTEGER)',
    'CREATE TABLE social_terms (id INTEGER PRIMARY KEY, name TEXT)',
    'CREATE TABLE social_post_tags (content_id INTEGER, term_id INTEGER)',
    "INSERT INTO users VALUES (1, 'public', 'active'), (2, 'hidden', 'inactive')",
    "INSERT INTO social_posts (id, author_id, body, published_at) VALUES (1, 1, 'first', '2020-01-01'), (2, 1, 'second', '2020-01-02'), (3, 2, 'hidden', '2020-01-03'), (4, 1, 'future', '2999-01-01')",
    "INSERT INTO social_terms VALUES (1, 'public'), (2, 'orphan'), (3, 'hidden'), (4, 'future')",
    'INSERT INTO social_post_tags VALUES (1, 1), (3, 3), (4, 4)',
] as $sql) { $db->exec($sql); }
$db->exec('ALTER TABLE social_posts ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT \'published\'');
$checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($why); } };
$db->queries = [];
$assert(array_column(Export::posts(), 'id') === [2, 1], 'Public exports exclude inactive authors and future publications.');
$assert(count($db->queries) === 2 && !str_contains(implode(' ', array_column($db->queries, 'sql')), 'COUNT('), 'Public exports use two bounded queries without per-post reaction or comment counts.');
$assert(count(Export::posts(1)) === 1 && Export::posts(100, 2) === [], 'Limits and author filtering are applied in SQL.');
$assert(array_column(Export::posts(50, null, 1), 'id') === [1], 'Tag exports use the requested public tag only.');
$assert(Sitemap::count('tags') === 1 && array_column(Sitemap::entries('tags', 1000, 0), 'url') === ['/tag/public'], 'Sitemap counts and entries exclude orphan, hidden-only and future-only tags.');
$assert(Sitemap::count('status') === 2 && count(Sitemap::entries('status', 1000, 0)) === 2, 'Sitemap and export visibility agree.');
$assert(Sitemap::count('pages') === 2 + count(\TinyCat\Support\Languages::language_packages()) && Sitemap::entries('pages', 1, 1)[0]['url'] === '/privacy', 'Public static pages and their translated policies share count and pagination rules.');
$assert(Sitemap::entries('status', 1000, 0)[0]['last_modified'] === null, 'Publication time is not presented as a modification timestamp.');
$db->exec("UPDATE social_posts SET status = 'hidden' WHERE id = 1");
$assert(array_column(Export::posts(), 'id') === [2], 'Moderation-hidden posts stay in storage but disappear from exports.');
$assert(Sitemap::count('status') === 1 && Sitemap::count('tags') === 0, 'Sitemaps exclude moderation-hidden posts and their orphaned public tags.');
$assert((int) $db->query('SELECT COUNT(*) FROM social_posts WHERE id = 1')->fetchColumn() === 1, 'Moderation visibility preserves the original content.');
$assert(Xml::escape("A\x01<&B") === 'A&lt;&amp;B', 'XML escapes entities and removes forbidden control characters.');
$xml = simplexml_load_string('<value>' . Xml::cdata('a]]>b') . '</value>');
$assert($xml !== false && (string) $xml === 'a]]>b', 'CDATA terminators remain valid and round-trip without changes.');
$pages = Sitemap::pageCounts();
$db->exec("UPDATE users SET status = 'inactive' WHERE id = 1");
$assert(Sitemap::pageCounts() === $pages && Sitemap::entries('status', 1000, 0) === [] && Export::posts() === [], 'A cached sitemap directory never preserves content after its author becomes hidden.');
// Machine metadata must not inherit a user-selected display format for ISO dates.
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['database' => ['driver' => 'sqlite'], 'app' => ['url' => 'https://example.test'], 'datetime' => ['iso' => 'Y', 'timezone' => 'UTC']]);
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Http\LocaleRouting::prepare('/pl/status/7');
$schema = \TinyCat\Social\Posts\StructuredData::post([
    'id' => 7, 'body' => 'Hello #Example', 'published_at' => '2020-01-01 12:00:00',
    'author_id' => 1, 'author_name' => 'Bot', 'author_role' => 'bot',
    'image_path' => '2026/11/0123456789abcdef0123456789abcdef.webp',
]);
$document = \TinyCat\Core\Ui\Metadata::document(['title' => "Title\x01", 'meta' => [
    'url' => '/pl/status/7', 'author' => '/pl/author/1', 'published_time' => '2020-01-01 12:00:00',
    'image' => '', 'image_alt' => 'Stale alt', 'image_width' => 800,
]]);
$assert($schema['datePublished'] === '2020-01-01T12:00:00+00:00' && $document['published_time'] === $schema['datePublished'], 'JSON-LD and Open Graph retain ISO 8601 dates independently of display configuration.');
$assert($schema['author']['@type'] === 'Organization' && $schema['author']['url'] === $document['author'] && $document['author'] === 'https://example.test/author/1', 'Bot entity types and canonical author URLs agree across metadata.');
$assert(!str_contains($document['title'], "\x01") && $document['image_alt'] === '' && $document['image_width'] === 0, 'Metadata removes forbidden title controls and stale properties of absent images.');

echo "PASS public export visibility and minimal data queries ({$checks} checks)\n";
