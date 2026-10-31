<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/bootstrap.php';

use TinyCat\Site\Dashboard;
use TinyCat\Site\DashboardViewData;
use TinyCat\Site\Views;
use TinyCat\Support\Localization;

(new ReflectionProperty(TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
TinyCat\Users\Authentication::reset(null, true);
Localization::locale('cs');
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE users (id INTEGER, username TEXT, role TEXT)');
$db->exec('CREATE TABLE content (id INTEGER, body TEXT, published_at TEXT)');
$db->exec('CREATE TABLE content_comments (id INTEGER, content_id INTEGER, user_id INTEGER, body TEXT, created_at TEXT)');
$db->exec("INSERT INTO users VALUES (7, 'author<unsafe>', 'user')");
$post = $db->prepare('INSERT INTO content VALUES (?, ?, ?)');
$post->execute([11, str_repeat('Long post <b>body</b> ', 60), '2020-01-01 00:00:00']);
$post->execute([12, '', '2099-01-01 00:00:00']);
$insert = $db->prepare('INSERT INTO content_comments VALUES (?, ?, ?, ?, ?)');
for ($id = 1; $id <= 9; $id++) {
    $insert->execute([$id, 11, 7, str_repeat('Comment ' . $id . ' <3 <script>alert(1)</script> ', 40), '2026-10-31 12:00:00']);
}
TinyCat\Database\Connection::set($db);
$data = Dashboard::data();
// Cover both diagnostic services regardless of the PHP extensions installed on CI.
$data['cache'] = ['available' => true, 'driver' => 'memcached'];
$data['memcached'] = ['configured' => true, 'available' => true, 'extension' => true];
$data['memcachedStats'] = ['servers' => 1, 'version' => '1.6', 'uptime' => 120, 'items' => 3, 'bytes' => 1024, 'limit_bytes' => 4096, 'hits' => 9, 'misses' => 1, 'evictions' => 0];
$data['opcache'] = ['enabled' => true, 'resettable' => true];
$data['opcacheStats'] = ['cached_scripts' => 12, 'used_memory' => 2048, 'wasted_memory' => 0, 'hits' => 8, 'misses' => 2];
$data['opcacheConfig'] = ['max_scripts' => 1000, 'memory_bytes' => 8192, 'validate_timestamps' => true, 'revalidate_freq' => 2, 'file_cache' => false];
$html = Views::dashboard($data);

if (($argv[1] ?? '') === '--fixture') {
    $css = TinyCat\Support\AssetGraph::stylesheet(TinyCat\Support\ClientAssets::sourceStyles('/admin'));
    $layout = TinyCat\Support\View::render('admin', ['content' => $html, 'actions' => '', 'adminIdentity' => '@admin', 'appName' => 'TinyCat', 'nav' => [], 'siteLogoUrl' => '', 'title' => Localization::t('common.dashboard'), 'authUser' => null, 'profileUrl' => '', 'profileNeedsEmail' => false, 'userMenu' => '', 'settingsEditor' => '', 'titleIcon' => 'dashboard'], $root . '/App/Site/templates');
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style></head><body class="admin-shell-page">' . $layout . '<script>';
    echo 'window.TinyCat={lifecycle:{register(name, service){if(service.init)service.init();if(service.hydrate)service.hydrate(document);}}};';
    foreach (['assets/core/dom.js', 'assets/core/icons.js', 'assets/ui/menus.js'] as $file) {
        echo preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/' . $file));
    }
    echo '</script></body></html>';
    exit;
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$model = DashboardViewData::prepare($data);
$check(count($model['comments']) === 8 && $data['recentComments'][0]['id'] === 9, 'Recent comments are bounded and ties are ordered by ID.');
$check($data['counts']['content'] === 1 && $data['counts']['scheduled_content'] === 1, 'Published and scheduled counts use actual publication times.');
$check($model['comments'][0]['author_url'] === '/author/7' && $model['comments'][0]['content_url'] === '/status/11', 'Author and post links use the actual records.');
$check(!str_contains($html, '<script>') && str_contains($html, '@author&lt;unsafe&gt;') && str_contains($html, '&lt;3'), 'User text remains escaped and emoticons are preserved.');
$doc = new DOMDocument();
@$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($doc);
$check($xpath->query('//article//h2')->length === 10 && str_contains($html, Localization::t('admin.stats.content')), 'Statistics retain their actual labels through the shared stat card.');
$check($xpath->query('//article//strong[text()="1"]')->length >= 2, 'Published and scheduled statistics show their prepared values.');
$check($xpath->query('//table[@id="dashboard-comments-table"]/tbody/tr')->length === 8, 'Comments render as table rows.');
$check($xpath->query('//table/thead/tr/th[@scope="col"]')->length === 4, 'Every column has an accessible heading.');
$check($xpath->query('//time[@datetime]')->length === 8, 'Comment timestamps retain machine-readable dates.');
$check(!str_contains($html, '/admin/moderation/reports') && !str_contains($html, '>content_comments<'), 'Dashboard omits unrelated navigation and database table names.');
$check(strpos($html, 'id="dashboard-comments"') < strpos($html, 'id="dashboard-cache"'), 'Recent activity precedes technical diagnostics.');
$check($xpath->query('//form[@action="/api/site/cache-resets"][@data-ajax-form]//input[@name="_csrf"]')->length === 1, 'OPcache reset retains its API action, AJAX behavior and CSRF token.');
$check(count($model['cache']['services'][0]['details']) === 7 && count($model['cache']['services'][1]['details']) === 8, 'All runtime diagnostics are retained.');
$data['recentComments'] = [];
$data['cache'] = ['available' => false, 'driver' => 'filesystem'];
$data['memcached'] = ['configured' => false, 'available' => false, 'extension' => false];
$data['opcache'] = ['enabled' => false, 'resettable' => false];
$empty = Views::dashboard($data);
$check(str_contains($empty, Localization::t('admin.no_recent_comments')) && !str_contains($empty, '<table'), 'Empty comments use the shared empty state.');
$check(!str_contains($empty, '<form') && str_contains($empty, Localization::t('admin.cache.filesystem')) && str_contains($empty, Localization::t('admin.cache.memcached_disabled')), 'Disabled services show the real fallback and do not offer reset.');
echo 'PASS dashboard data, shared components, safe comments and cache diagnostics (' . $checks . " checks)\n";
