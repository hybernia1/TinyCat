<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Core\Discovery\Sitemap;
use TinyCat\Social\Posts\Export;
use TinyCat\Support\Config;
use TinyCat\Support\Xml;

(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['database' => ['driver' => 'mysql'], 'app' => ['url' => 'https://example.test']]);
require __DIR__ . '/../../../tools/performance/lib/ProfileDatabase.php';
$db = \tinycat_test_database_as(\TinyCat\Tools\Performance\ProfileDatabase::class);
Connection::set($db);
// No reactions, comments, previews or session tables: exports must not load UI state.
foreach ([
    'CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254), status VARCHAR(24))',
    'CREATE TABLE social_posts (kind VARCHAR(40) NOT NULL DEFAULT \'post\', id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, author_id INT UNSIGNED, body TEXT, published_at DATETIME, INDEX content_feed_index (published_at,id), INDEX content_sidebar_index (published_at,author_id,id), INDEX content_author_index (author_id,published_at,id))',
    'CREATE TABLE social_post_images (content_id INT UNSIGNED, path TEXT, bytes INTEGER)',
    'CREATE TABLE social_terms (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT)',
    'CREATE TABLE social_post_tags (content_id INT UNSIGNED, term_id INT UNSIGNED)',
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
$fields = simplexml_load_string('<oembed>' . Xml::elements(['title' => '<unsafe> & text', 'width' => 12, 'enabled' => true]) . '</oembed>');
$assert($fields !== false && (string) $fields->title === '<unsafe> & text' && (string) $fields->width === '12', 'Flat protocol XML validates fields and escapes their scalar values.');
foreach ([['bad><injected' => 'value'], ['nested' => ['unexpected']], ['number' => INF]] as $invalid) {
    try { Xml::elements($invalid); throw new RuntimeException('Invalid XML field was accepted.'); }
    catch (LogicException) { $assert(true, 'Invalid XML names and unsupported values are rejected.'); }
}
$rssItem = ['title' => 'Audio', 'url' => 'https://example.test/audio', 'published_date' => '', 'creator' => 'Owner', 'creator_url' => '', 'categories' => [], 'image_url' => '', 'html' => 'Description', 'enclosure' => ['url' => 'https://example.test/audio.mp3', 'type' => 'audio/mpeg', 'length' => 321]];
$rss = simplexml_load_string(\TinyCat\Core\Ui\Rss::render('Feed', 'Description', 'https://example.test/', 'https://example.test/feed', [$rssItem]));
$assert($rss !== false && (string) $rss->channel->item->enclosure['type'] === 'audio/mpeg' && (string) $rss->channel->item->enclosure['length'] === '321', 'Generic RSS preserves owner-supplied enclosure metadata instead of assuming WebP.');
// Exercise deep offset partitioning with gaps, hidden rows, inactive authors and optional images.
for ($i = 0; $i < 45; $i++) {
    $id = 1000 + $i * 2;
    $db->prepare('INSERT INTO social_posts(id,author_id,body,published_at,status) VALUES (?,?,?,?,?)')->execute(
        [$id, $i % 7 === 0 ? 2 : 1, 'sitemap fixture', $i % 11 === 0 ? '2999-01-01' : '2020-01-01', $i % 5 === 0 ? 'hidden' : 'published']);
    if ($i % 3 === 0) { $db->prepare('INSERT INTO social_post_images VALUES (?,?,?)')->execute([$id, '2026/11/' . str_pad(dechex($id), 32, '0', STR_PAD_LEFT) . '.webp', 123]); }
    $db->prepare('INSERT INTO social_terms VALUES (?,?)')->execute([$id, 'tag' . $i]);
    $db->prepare('INSERT INTO social_post_tags VALUES (?,?)')->execute([$id, $id]);
}
foreach (['status', 'tags'] as $section) {
    $all = Sitemap::entries($section, 1000, 0);
    $partitioned = [];
    for ($offset = 0; $offset < count($all); $offset += 4) {
        $db->queries = [];
        $part = Sitemap::entries($section, 4, $offset);
        $assert(count($db->queries) === 1, 'Sitemap page uses a single SQL statement: ' . $section);
        array_push($partitioned, ...$part);
    }
    $assert($partitioned === $all && count(array_unique(array_column($all, 'url'))) === count($all), 'Deep sitemap pages preserve exact URLs, images, order and partitioning: ' . $section);
    $assert(Sitemap::entries($section, 4, count($all) + 10) === [], 'An offset past the visible selection stays empty: ' . $section);
}
$pages = Sitemap::pageCounts();
\TinyCat\Core\Modules\Resources::withCatalog(\TinyCat\Core\Modules\Loader::fromSelection(dirname(__DIR__, 3), [])->catalog, static function () use ($assert): void {
    $assert(Sitemap::sections() === ['pages'] && array_keys(Sitemap::pageCounts()) === ['pages'], 'Changing to core-only registration cannot reuse cached Social sitemap sections.');
    $assert(\TinyCat\Core\Ui\Information::sections('llms') === '' && \TinyCat\Core\Ui\Information::sections('llms-full') === '', 'Information documents use the same active owner selection as the sitemap.');
});
$assert(Sitemap::pageCounts() === $pages && Sitemap::hasSection('tags'), 'Restoring the Social selection restores only its registered sitemap sections.');
$db->exec("UPDATE users SET status = 'inactive' WHERE id = 1");
$assert(Sitemap::pageCounts() === $pages && Sitemap::entries('status', 1000, 0) === [] && Export::posts() === [], 'A cached sitemap directory never preserves content after its author becomes hidden.');
// Machine metadata must not inherit a user-selected display format for ISO dates.
(new ReflectionProperty(Config::class, 'config'))->setValue(null, ['database' => ['driver' => 'mysql'], 'app' => ['url' => 'https://example.test'], 'datetime' => ['iso' => 'Y', 'timezone' => 'UTC']]);
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
