<?php
declare(strict_types=1);

// Temporary synthetic media belong exclusively to an isolated performance DB.
$options = getopt('', ['config:', 'output:', 'baseline:', 'candidate:', 'probe-only', 'focused', 'home']);
$configPath = realpath((string) ($options['config'] ?? ''));
$output = (string) ($options['output'] ?? '');
if (!$configPath || $output === '' || file_exists($output)) { throw new RuntimeException('Supply config and a new output directory.'); }
$config = require $configPath;
$settings = $config['database'];
if (preg_match('/^tinycat_perf_[a-z0-9_]+$/D', $settings['name']) !== 1) { throw new RuntimeException('Isolated performance database required.'); }
$db = new PDO('mysql:host=' . $settings['host'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4', $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $db->query('SELECT COUNT(*) FROM content_images')->fetchColumn() !== 0 || (int) $db->query("SELECT COUNT(*) FROM links WHERE link_type <> 'link'")->fetchColumn() !== 0) { throw new RuntimeException('Unexpected fixture baseline; nothing changed.'); }
mkdir($output, 0775, true);
$output = realpath($output);
$sources = [];
foreach (['baseline', 'candidate'] as $variant) {
    if (isset($options[$variant])) { $sources[$variant] = hash_file('sha256', rtrim((string) $options[$variant], '/\\') . '/App/Status/Feed.php'); }
}
file_put_contents($output . '/sources.json', json_encode(['feed_php_sha256' => $sources, 'php' => PHP_VERSION, 'mysql' => $db->query('SELECT VERSION()')->fetchColumn()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$run = static function (array $command): void {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start benchmark.'); }
    fclose($pipes[0]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Benchmark failed.'); }
};
file_put_contents($output . '/recovery.json', json_encode(['database' => $settings['name'], 'restore_sql' => ["DELETE FROM content_images WHERE path LIKE 'tag-query-probe/%'", "UPDATE links SET link_type = 'link' WHERE link_type IN ('video', 'audio')"]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
try {
    $db->exec("INSERT INTO content_images (content_id, path, width, height, bytes) SELECT id, CONCAT('tag-query-probe/', id, '.webp'), 640, 480, 10000 FROM content WHERE MOD(id, 10) = 0");
    $db->exec("UPDATE links SET link_type = CASE WHEN MOD(id, 3) = 0 THEN 'video' WHEN MOD(id, 3) = 1 THEN 'audio' ELSE 'link' END");
    $run([PHP_BINARY, __DIR__ . '/tag-query-probe.php', '--config=' . $configPath, '--output=' . $output . '/plans.json']);
    if (!isset($options['probe-only'])) {
        $paths = [];
        foreach (isset($options['focused']) ? ['all', 'text'] : ['all', 'images', 'links', 'video', 'audio', 'text'] as $type) { $paths[] = '/api/statuses?context=tag&tag=community&limit=50' . ($type === 'all' ? '' : '&media=' . $type); }
        if (isset($options['home'])) {
            $paths = ['/api/statuses?context=tag&tag=community&limit=50', '/api/statuses?context=tag&tag=community&limit=50&media=text'];
            foreach (['images', 'links', 'video', 'audio'] as $type) { $paths[] = '/api/statuses?limit=50&media=' . $type; }
        }
        foreach (['baseline', 'candidate', 'candidate', 'baseline'] as $round => $variant) {
            $root = realpath((string) ($options[$variant] ?? ''));
            if (!$root || !is_file($root . '/index.php')) { throw new RuntimeException('Expected application root: ' . $variant); }
            $run([PHP_BINARY, dirname(__DIR__) . '/profile-performance.php', '--config=' . $configPath, '--root=' . $root,
                '--output=' . $output . '/' . $variant . '-' . $round . '.json', '--requests=40', '--workers=4', '--paths=' . implode(',', $paths)]);
        }
    }
} finally {
    $db->exec("DELETE FROM content_images WHERE path LIKE 'tag-query-probe/%'");
    $db->exec("UPDATE links SET link_type = 'link' WHERE link_type IN ('video', 'audio')");
    $restored = ['images' => (int) $db->query('SELECT COUNT(*) FROM content_images')->fetchColumn(), 'non_link_types' => (int) $db->query("SELECT COUNT(*) FROM links WHERE link_type <> 'link'")->fetchColumn()];
    file_put_contents($output . '/restored.json', json_encode($restored, JSON_THROW_ON_ERROR));
    echo 'RESTORED ' . json_encode($restored, JSON_THROW_ON_ERROR) . PHP_EOL;
}
