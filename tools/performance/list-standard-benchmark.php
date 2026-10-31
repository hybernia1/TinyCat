<?php
declare(strict_types=1);

// Alternating real HTTP comparison. No synthetic media or database writes.
$options = getopt('', ['config:', 'output:', 'baseline:', 'candidate:', 'search-only']);
$configPath = realpath((string) ($options['config'] ?? ''));
$output = (string) ($options['output'] ?? '');
if (!$configPath || $output === '' || file_exists($output)) { throw new RuntimeException('Supply a config and new output directory.'); }
$config = require $configPath;
$settings = $config['database'];
if (preg_match('/^tinycat_perf_[a-z0-9_]+$/D', $settings['name']) !== 1) { throw new RuntimeException('Isolated performance database required.'); }
$db = new PDO('mysql:host=' . $settings['host'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4', $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $db->query("SELECT COUNT(*) FROM links WHERE link_type = 'video'")->fetchColumn() !== 0) { throw new RuntimeException('This scenario requires no video matches.'); }
$cursor = $db->query('SELECT id, published_at FROM content ORDER BY published_at DESC, id DESC LIMIT 1 OFFSET 50000')->fetch(PDO::FETCH_ASSOC);
$paths = ['/api/statuses?limit=50&media=video', '/api/search/results?q=performance&type=content&limit=50',
    '/api/search/results?' . http_build_query(['q' => 'performance', 'type' => 'content', 'limit' => 50, 'cursor_at' => $cursor['published_at'], 'cursor_id' => $cursor['id']])];
if (isset($options['search-only'])) {
    array_shift($paths);
    foreach (['10%', 'https', 'zzunmatchedprobe'] as $query) { $paths[] = '/api/search/results?' . http_build_query(['q' => $query, 'type' => 'content', 'limit' => 50]); }
}
mkdir($output, 0775, true); $output = realpath($output);
$sources = [];
foreach (['baseline', 'candidate'] as $variant) {
    $root = realpath((string) ($options[$variant] ?? ''));
    if (!$root || !is_file($root . '/index.php')) { throw new RuntimeException('Expected application root.'); }
    foreach (['App/Status/Feed.php', 'App/Search/Queries.php'] as $file) { $sources[$variant][$file] = hash_file('sha256', $root . '/' . $file); }
}
file_put_contents($output . '/sources.json', json_encode($sources, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach (['baseline', 'candidate', 'candidate', 'baseline'] as $round => $variant) {
    $command = [PHP_BINARY, dirname(__DIR__) . '/profile-performance.php', '--config=' . $configPath, '--root=' . $options[$variant],
        '--output=' . $output . '/' . $variant . '-' . $round . '.json', '--requests=40', '--workers=4', '--paths=' . implode(',', $paths)];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start benchmark.'); }
    fclose($pipes[0]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Benchmark failed.'); }
}
