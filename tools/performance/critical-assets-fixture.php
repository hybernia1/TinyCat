<?php
declare(strict_types=1);

// Export anonymous SSR pages and production-minified assets for a browser experiment.
$options = getopt('', ['config:', 'output:']);
$configPath = realpath((string) ($options['config'] ?? ''));
$output = (string) ($options['output'] ?? '');
if (!$configPath || $output === '' || file_exists($output)) { throw new RuntimeException('Supply isolated config and a new output directory.'); }
$config = require $configPath;
$settings = $config['database'];
if (preg_match('/^tinycat_perf_[a-z0-9_]+$/D', $settings['name']) !== 1) { throw new RuntimeException('Isolated performance database required.'); }
$root = dirname(__DIR__, 2);
require __DIR__ . '/ProfileServerPool.php';
$db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $settings['host'], $settings['port'] ?? 3306, $settings['name']), $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$id = (int) $db->query("SELECT c.id FROM content c INNER JOIN users u ON u.id = c.author_id WHERE u.status = 'active' AND c.published_at <= NOW() ORDER BY c.published_at DESC, c.id DESC LIMIT 1")->fetchColumn();
mkdir($output, 0775, true); $output = realpath($output);
$pool = new \TinyCat\Tools\Performance\ProfileServerPool($root, $configPath, $output . '/ssr.jsonl', 1);
$get = static function (string $path) use ($pool): array {
    $url = $pool->urls($path)[0]; $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept-Language: en'], CURLOPT_TIMEOUT => 60]);
    $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
    if (!is_string($body) || $status !== 200 || preg_match('/(?:Fatal error|Warning|Notice|Deprecated):/', $body)) { throw new RuntimeException('SSR export failed: ' . $path . '/' . $status); }
    $origin = (string) parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT);
    return ['html' => str_replace($origin, '', $body), 'origin' => $origin];
};
try {
    $pages = [];
    foreach (['home' => '/', 'author' => '/author/2', 'status' => '/status/' . $id] as $name => $path) {
        $page = $get($path); $page['path'] = $path;
        preg_match('/<html\s+lang="([^"]+)"/', $page['html'], $match); $page['locale'] = $match[1] ?? 'en';
        $pages[$name] = $page;
    }
    $sidebar = $get('/api/site/sidebar');
} finally { $pool->stop(); }
define('TINYCAT', true);
require_once $root . '/App/autoload.php';
$compile = require dirname(__DIR__) . '/compile-assets.php'; $files = $compile();
$manifest = json_decode($files['assets/build/manifest.json'], true, flags: JSON_THROW_ON_ERROR);
$client = $manifest['clients']['/']; $selected = [];
foreach ($pages as &$page) {
    if (!isset($client['catalogs'][$page['locale']])) { throw new RuntimeException('Unsupported fixture locale.'); }
    $page['scripts'] = [$client['runtime']['min'], $client['components'], $client['catalogs'][$page['locale']], $client['app']['min']];
    $page['style'] = $client['styles']['min'];
    foreach ([...$page['scripts'], $page['style']] as $url) { $selected[$url] = $files[ltrim($url, '/')]; }
}
unset($page);
foreach ($manifest['assets'] as $variants) {
    foreach ($variants as $url) { $selected[$url] = $files[ltrim($url, '/')]; }
}
file_put_contents($output . '/fixture.json', json_encode(['pages' => $pages, 'assets' => $selected, 'sidebar' => json_decode($sidebar['html'], true, flags: JSON_THROW_ON_ERROR), 'method' => 'Actual anonymous SSR from isolated dataset; compiled minified assets; static replay excludes backend timing.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo 'EXPORTED ' . count($pages) . ' pages, ' . count($selected) . ' assets to ' . $output . PHP_EOL;
