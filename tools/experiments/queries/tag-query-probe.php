<?php
declare(strict_types=1);

// Read-only query-plan probe. Credentials are never included in its output.
$options = getopt('', ['config:', 'output:']);
$config = require (string) ($options['config'] ?? '');
$settings = $config['database'];
if (preg_match('/^tinycat_perf_[a-z0-9_]+$/D', $settings['name']) !== 1) { throw new RuntimeException('Isolated performance database required.'); }
$db = new PDO('mysql:host=' . $settings['host'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4', $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$term = $db->query("SELECT id FROM terms WHERE name = 'community'")->fetchColumn();
if (!$term) { throw new RuntimeException('Expected community tag.'); }
$image = 'EXISTS (SELECT 1 FROM content_images fi WHERE fi.content_id = c.id)';
$link = 'EXISTS (SELECT 1 FROM content_links fl WHERE fl.content_id = c.id)';
$filters = ['all' => '', 'images' => $image, 'links' => $link,
    'video' => "EXISTS (SELECT 1 FROM content_links fl INNER JOIN links l ON l.id = fl.link_id WHERE fl.content_id = c.id AND l.link_type = 'video')",
    'audio' => "EXISTS (SELECT 1 FROM content_links fl INNER JOIN links l ON l.id = fl.link_id WHERE fl.content_id = c.id AND l.link_type = 'audio')",
    'text' => 'NOT ' . $image . ' AND NOT ' . $link];
$sources = [
    'baseline' => 'content_tags ct INNER JOIN content c ON c.id = ct.content_id INNER JOIN users u ON u.id = c.author_id',
    'tag-first' => 'content_tags ct STRAIGHT_JOIN content c ON c.id = ct.content_id STRAIGHT_JOIN users u ON u.id = c.author_id',
    'feed-first' => 'content c FORCE INDEX (content_feed_index) STRAIGHT_JOIN content_tags ct ON ct.content_id = c.id STRAIGHT_JOIN users u ON u.id = c.author_id',
    'bounded' => "(SELECT id, author_id, published_at FROM content FORCE INDEX (content_feed_index) WHERE published_at <= '2026-10-31 12:00:00' AND EXISTS (SELECT 1 FROM content_tags enough WHERE enough.term_id = " . (int) $term . " LIMIT 1 OFFSET 49) ORDER BY published_at DESC, id DESC LIMIT 800) c STRAIGHT_JOIN content_tags ct ON ct.content_id = c.id STRAIGHT_JOIN users u ON u.id = c.author_id",
];
$report = ['mysql' => $db->query('SELECT VERSION()')->fetchColumn(), 'tag_relations' => (int) $db->query('SELECT COUNT(*) FROM content_tags WHERE term_id = ' . (int) $term)->fetchColumn(), 'queries' => []];
foreach ($filters as $filter => $condition) {
    $expected = null;
    foreach ($sources as $variant => $source) {
        if ($variant === 'bounded' && $filter !== 'all' && $filter !== 'text') { continue; }
        $sql = 'SELECT c.id FROM ' . $source . ' WHERE ct.term_id = ' . (int) $term . " AND u.status = 'active' AND c.published_at <= '2026-10-31 12:00:00'" . ($condition ? ' AND (' . $condition . ')' : '') . ' ORDER BY c.published_at DESC, c.id DESC LIMIT 50';
        $times = [];
        for ($i = 0; $i < 4; $i++) {
            $start = hrtime(true); $ids = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN); $times[] = (hrtime(true) - $start) / 1000000;
            if ($expected !== null && $expected !== $ids) { throw new RuntimeException('Mismatched IDs: ' . $filter . '/' . $variant); }
            $expected = $ids;
        }
        $plan = $db->query('EXPLAIN ANALYZE ' . $sql)->fetchColumn();
        $report['queries'][$filter][$variant] = ['sql' => $sql, 'ms' => $times, 'ids' => $ids, 'plan' => $plan];
        echo $filter . '/' . $variant . ': ' . json_encode($times) . PHP_EOL;
    }
}
file_put_contents((string) $options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
