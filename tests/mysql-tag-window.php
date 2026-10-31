<?php
declare(strict_types=1);

// Opt-in real MySQL test; all fixture DML is rolled back.
$options = getopt('', ['config:', 'report:']);
if (!isset($options['config'])) { echo "SKIP MySQL tag window: supply --config for an isolated performance database.\n"; exit; }
require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';
require_once dirname(__DIR__) . '/tools/performance/ProfileDatabase.php';
$config = require (string) $options['config'];
$settings = $config['database'];
if (preg_match('/^tinycat_perf_[a-z0-9_]+$/D', $settings['name']) !== 1) { throw new RuntimeException('Isolated performance database required.'); }
(new ReflectionProperty(\TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $config);
$db = new \TinyCat\Tools\Performance\ProfileDatabase('mysql:host=' . $settings['host'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4', $settings['user'], $settings['password']);
\TinyCat\Database\Connection::set($db);
\TinyCat\Users\Authentication::reset(null, true);
$archive = new ReflectionMethod(\TinyCat\Status\Feed::class, 'archive_query');
$checks = 0;
$measurements = [];
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$compare = static function (string $name, int $term, int $limit, string $at = '', int $id = 0, string $type = '') use ($archive, $assert, $db, &$measurements): void {
    $query = $archive->invoke(null, \TinyCat\Status\Feed::public_status_tag_id_query($term), $type);
    $expected = \TinyCat\Status\Feed::public_status_ids_page(\TinyCat\Status\Feed::public_status_apply_cursor($query, $at, $id), $limit);
    $db->queries = [];
    $actual = \TinyCat\Status\Feed::public_status_items_by_tag($name, $limit, $at, $id, $type);
    $assert(array_map('intval', array_column($actual, 'id')) === $expected, 'Ordered ID mismatch: ' . $name . '/' . $type . '/' . $limit . '/' . $at);
    if ($type === '' || $type === 'text') {
        $probes = array_filter($db->queries, static fn (array $q): bool => str_contains($q['sql'], 'FROM (SELECT c.id, c.author_id, c.published_at'));
        $assert(count($probes) === 1, 'Exactly one bounded probe per MySQL request.');
        $fallback = array_filter($db->queries, static fn (array $q): bool => str_contains($q['sql'], 'FROM content_tags ct INNER JOIN content c'));
        $measurements[] = ['type' => $type, 'limit' => $limit, 'fallback' => count($fallback) > 0, 'probe_ms' => array_values($probes)[0]['ms'], 'sql_count' => count($db->queries)];
    }
};
$counts = $db->query('SELECT COUNT(*) FROM content')->fetchColumn();
$db->beginTransaction();
try {
    $term = (int) $db->query("SELECT id FROM terms WHERE name = 'community'")->fetchColumn();
    $cursor = $db->query('SELECT id, published_at FROM content ORDER BY published_at DESC, id DESC LIMIT 1 OFFSET 50000')->fetch();
    foreach (['', 'text'] as $type) {
        foreach ([1, 24, 50, 100] as $limit) {
            $compare('community', $term, $limit, '', 0, $type);
            $compare('community', $term, $limit, $cursor['published_at'], (int) $cursor['id'], $type);
        }
        $compare('community', $term, 0, 'invalid', 5, $type);
        $compare('community', $term, 500, '2999-01-01 00:00:00', -1, $type);
    }
    $name = 'windowprobe' . bin2hex(random_bytes(5));
    $db->prepare('INSERT INTO terms (name) VALUES (?)')->execute([$name]); $dense = (int) $db->lastInsertId();
    $sparseName = $name . 'sparse';
    $db->prepare('INSERT INTO terms (name) VALUES (?)')->execute([$sparseName]); $sparse = (int) $db->lastInsertId();
    $author = (int) $db->query("SELECT id FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    $post = $db->prepare('INSERT INTO content (author_id, body, published_at) VALUES (?, ?, ?)');
    $tag = $db->prepare('INSERT INTO content_tags (content_id, term_id) VALUES (?, ?)');
    $ids = [];
    for ($i = 0; $i < 300; $i++) {
        $post->execute([$author, 'Transactional tag-window fixture', '2026-10-30 00:00:00']);
        $ids[] = $id = (int) $db->lastInsertId(); $tag->execute([$id, $dense]);
    }
    $linkIds = [];
    foreach (['video', 'audio', 'link'] as $type) {
        $url = 'https://window-fixture.invalid/' . $name . '/' . $type;
        $db->prepare('INSERT INTO links (normalized_url, url_hash, provider, link_type, title) VALUES (?, ?, ?, ?, ?)')->execute([$url, hash('sha256', $url), '', $type, 'Window fixture']);
        $linkIds[] = (int) $db->lastInsertId();
    }
    foreach ($ids as $index => $id) {
        if ($index % 4 === 0) { $db->prepare('INSERT INTO content_images (content_id, path, width, height, bytes) VALUES (?, ?, 1, 1, 1)')->execute([$id, 'transactional-window-fixture-' . $id . '.webp']); }
        if ($index % 2 === 0) { $db->prepare('INSERT INTO content_links (content_id, link_id) VALUES (?, ?)')->execute([$id, $linkIds[(int) ($index / 2) % 3]]); }
    }
    foreach (['images', 'links', 'video', 'audio'] as $type) {
        foreach ([1, 24, 100] as $limit) {
            foreach ([['', 0], ['2026-10-30 00:00:00', $ids[100]]] as [$at, $id]) {
                $reference = $archive->invoke(null, \TinyCat\Status\Feed::public_status_id_query(false), $type);
                $expected = \TinyCat\Status\Feed::public_status_ids_page(\TinyCat\Status\Feed::public_status_apply_cursor($reference, $at, $id), $limit);
                $db->queries = [];
                $actual = \TinyCat\Status\Feed::public_status_items_cursor($limit, $at, $id, $type);
                $assert(array_map('intval', array_column($actual, 'id')) === $expected, 'Home media window/fallback: ' . $type . '/' . $limit . '/' . $at);
                $assert(count(array_filter($db->queries, static fn (array $q): bool => str_contains($q['sql'], 'FROM (SELECT c.id, c.author_id, c.published_at'))) === 1, 'Home media has one bounded probe.');
            }
        }
    }
    $post->execute([$author, 'Future fixture', '2999-01-01 00:00:00']); $tag->execute([(int) $db->lastInsertId(), $dense]);
    $old = $db->query('SELECT id FROM content ORDER BY published_at ASC, id ASC LIMIT 8')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($old as $id) { $tag->execute([(int) $id, $sparse]); }
    foreach (['', 'text'] as $type) {
        $compare($name, $dense, 24, '', 0, $type);
        $compare($name, $dense, 24, '2026-10-30 00:00:00', $ids[100], $type);
        $compare($sparseName, $sparse, 50, '', 0, $type);
        $compare($name, $dense, 100, '1900-01-01 00:00:00', PHP_INT_MAX, $type);
        for ($i = 0; $i < 5; $i++) { $compare($sparseName, $sparse, 50, '', 0, $type); }
    }
    $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$author]);
    $compare($name, $dense, 24);
    $compare($name, $dense, 24, '', 0, 'text');
} finally { $db->rollBack(); }
$assert($db->query('SELECT COUNT(*) FROM content')->fetchColumn() === $counts, 'Fixture posts rolled back.');
if (isset($options['report'])) { file_put_contents((string) $options['report'], json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"); }
echo 'PASS MySQL bounded home/tag windows, deep/tied cursors, sparse fallback, limits and visibility (' . $checks . " checks)\n";
