<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

use TinyCat\Social\Site\Dashboard;
use TinyCat\Support\Localization;

(new ReflectionProperty(TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
TinyCat\Core\Identity\Authentication::reset(null, true);
Localization::locale('cs');
$db = \tinycat_test_database();
$db->exec('CREATE TABLE users (id INTEGER, username VARCHAR(254), role VARCHAR(40), avatar_exists INTEGER DEFAULT 0, avatar_version VARCHAR(32), avatar_appearance TEXT, updated_at DATETIME)');
$db->exec('CREATE TABLE social_posts (kind VARCHAR(40) NOT NULL DEFAULT \'post\', id INTEGER, body TEXT, published_at DATETIME, INDEX content_feed_index (published_at,id))');
$db->exec('CREATE TABLE social_comments (id INTEGER, content_id INT UNSIGNED, user_id INT UNSIGNED, body TEXT, created_at DATETIME)');
$db->exec('CREATE TABLE social_links (id INTEGER)');
$db->exec('CREATE TABLE social_terms (id INTEGER)');
$db->exec('INSERT INTO social_links VALUES (1), (2)');
$db->exec('INSERT INTO social_terms VALUES (1)');
$db->exec("INSERT INTO users (id, username, role) VALUES (7, 'author<unsafe>', 'user')");
$db->exec("INSERT INTO users (id, username, role) VALUES (8, 'admin', 'admin'), (9, 'importer', 'bot')");
$post = $db->prepare('INSERT INTO social_posts (id, body, published_at) VALUES (?, ?, ?)');
$post->execute([11, str_repeat('Long post <b>body</b> ', 60), '2020-01-01 00:00:00']);
$post->execute([12, '', '2099-01-01 00:00:00']);
$insert = $db->prepare('INSERT INTO social_comments VALUES (?, ?, ?, ?, ?)');
for ($id = 1; $id <= 9; $id++) {
    $insert->execute([$id, 11, 7, str_repeat('Comment ' . $id . ' <3 <script>alert(1)</script> ', 40), '2026-10-31 12:00:00']);
}
TinyCat\Database\Connection::set($db);
$comments = Dashboard::commentsWidget();
$widgets = [
    ['id' => 'fixture.accounts', 'type' => 'metrics', 'title' => 'Accounts', 'icon' => 'users', 'data' => ['id' => 'fixture.accounts', 'groups' => [['id' => 'fixture.accounts', 'label' => 'Users'], ['id' => 'fixture.content', 'label' => 'Content']], 'items' => [['label' => 'Users', 'value' => 3], ['label' => 'Empty', 'value' => 0]], 'url' => '/admin/users']],
    ['id' => 'fixture.comments', 'type' => 'list', 'title' => Localization::t('admin.recent_comments'), 'icon' => 'message-circle', 'data' => $comments],
    ['id' => 'fixture.status', 'type' => 'status', 'title' => 'Status', 'icon' => 'shield', 'data' => ['text' => 'Healthy', 'tone' => 'success']],
];
$html = TinyCat\Support\Components::render('ui/dashboard', ['widgets' => $widgets]);
if (in_array($argv[1] ?? '', ['--fixture', '--cache-fixture'], true)) {
    if ($argv[1] === '--cache-fixture') {
        $html = TinyCat\Support\Components::render('ui/cache-page', TinyCat\Core\Ui\Admin\CachePage::model());
    }
    $css = TinyCat\Support\AssetGraph::stylesheet(TinyCat\Support\ClientAssets::sourceStyles('/admin'));
    $layout = TinyCat\Support\View::render('shell', ['content' => $html, 'actions' => '', 'adminIdentity' => '@admin', 'appName' => 'TinyCat', 'nav' => [], 'siteLogoUrl' => '', 'title' => Localization::t('common.dashboard'), 'authUser' => null, 'profileUrl' => '', 'profileNeedsEmail' => false, 'userMenu' => '', 'titleIcon' => 'dashboard'], $root . '/App/Core/Ui/Admin/templates');
    echo '<!doctype html><html lang="cs" data-ui-density="compact"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style></head><body class="admin-shell-page">' . $layout . '</body></html>';
    exit;
}
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$check(str_contains($html, '>0</strong>') && str_contains($html, 'data-dashboard-stats-form'), 'The native selector and zero-valued counts render in the shared frame.');
$check(count($comments['items']) === 5 && str_contains($comments['items'][0]['detail'], 'Comment 9'), 'Owner selects a bounded list in stable order.');
$check(!str_contains($html, '<script>') && str_contains($html, '@author&lt;unsafe&gt;') && str_contains($html, '&lt;3'), 'Widget data is safely escaped.');
$check(substr_count($html, 'data-dashboard-widget=') === 3 && !str_contains($html, '<table') && !str_contains($html, 'cache-resets'), 'Dashboard uses shared typed frames and no legacy tables or diagnostics.');
$check(str_contains($html, '/status/11') && str_contains($html, '/admin/users'), 'Owners retain their canonical links.');
$registries = TinyCat\Core\Modules\Registrations::current();
$calls = 0;
$hidden = new TinyCat\Core\Modules\DashboardWidget('fixture.hidden', static function () use (&$calls): array { $calls++; return []; }, type: 'list', visible: static fn (): bool => false);
$failed = new TinyCat\Core\Modules\DashboardWidget('fixture.failed', static function (): array { throw new RuntimeException('Fixture failure'); }, type: 'list');
$healthy = new TinyCat\Core\Modules\DashboardWidget('fixture.good', static fn (): array => ['items' => array_fill(0, 20, ['label' => 'Good', 'value' => 1])], type: 'metrics');
TinyCat\Core\Modules\Registrations::configure(new TinyCat\Core\Modules\Registries(['fixture.owner' => new TinyCat\Core\Modules\Contributions(widgets: [$hidden, $failed, $healthy])]));
$models = TinyCat\Core\Ui\Admin\Dashboard::widgets();
$check($calls === 0 && count($models) === 2 && $models[0]['type'] === 'status' && count($models[1]['data']['items']) === 8, 'Invisible widgets do not load, failures are isolated, and display bounds are enforced.');
TinyCat\Core\Modules\Registrations::configure($registries);
$loads = [0, 0];
$groups = [];
foreach ([0, 1] as $index) {
    $groups[] = new TinyCat\Core\Modules\DashboardStats('fixture.group' . $index, static fn (): string => 'Group', static function () use (&$loads, $index): array { $loads[$index]++; return ['items' => [['key' => 'count', 'value' => 7]]]; }, fields: static fn (): array => ['count' => ['label' => ['t' => 'common.users']]]);
}
$groups[] = new TinyCat\Core\Modules\DashboardStats('fixture.hidden', static function (): string { throw new RuntimeException('A hidden group must not resolve its label.'); }, static function () use (&$loads): array { $loads[0]++; return []; }, fields: static fn (): array => [], visible: static fn (): bool => false);
$groups[] = new TinyCat\Core\Modules\DashboardStats('fixture.badlabel', static function (): string { throw new RuntimeException('Bad owner metadata'); }, static function () use (&$loads): array { $loads[0]++; return []; }, fields: static fn (): array => []);
TinyCat\Core\Modules\Registrations::configure(new TinyCat\Core\Modules\Registries(['fixture.owner' => new TinyCat\Core\Modules\Contributions(statistics: $groups)]));
$check(count(TinyCat\Core\Ui\Admin\Statistics::groups()) === 2 && count(TinyCat\Core\Ui\Admin\Statistics::fieldLookup()) === 2 && $loads === [0, 0], 'Hidden and failed owner metadata is isolated without loading any statistics.');
$resource = TinyCat\Core\Ui\Admin\Statistics::resource('fixture.group1');
$check($loads === [0, 1] && $resource['items'] === [['key' => 'fixture.group1.count', 'value' => 7]], 'Only the selected owner loads canonical counts.');
$missing = false;
try { TinyCat\Core\Ui\Admin\Statistics::resource('fixture.missing'); } catch (TinyCat\Http\HttpException $error) { $missing = $error->getCode() === 404; }
$check($missing && $loads === [0, 1], 'Unknown groups do not load another owner.');
TinyCat\Core\Modules\Registrations::configure($registries);
$cache = TinyCat\Core\Ui\Admin\Diagnostics::render();
$check(!str_contains($cache, 'data-dashboard-widget') && str_contains($cache, 'Memcached'), 'Cache services remain outside dashboard widgets.');
$runtime = ['cache' => ['available' => true, 'driver' => 'memcached'], 'memcached' => ['configured' => true, 'available' => true, 'extension' => true], 'memcachedStats' => ['servers' => 1, 'version' => '1.6', 'uptime' => 120, 'items' => 3, 'bytes' => 1024, 'limit_bytes' => 4096, 'hits' => 9, 'misses' => 1, 'evictions' => 0], 'opcache' => ['enabled' => true, 'resettable' => true], 'opcacheStats' => ['cached_scripts' => 12, 'used_memory' => 2048, 'wasted_memory' => 0, 'hits' => 8, 'misses' => 2], 'opcacheConfig' => ['max_scripts' => 1000, 'memory_bytes' => 8192, 'validate_timestamps' => true, 'revalidate_freq' => 2, 'file_cache' => false]];
$runtimeModel = TinyCat\Core\Ui\Admin\Diagnostics::model($runtime);
$check(count($runtimeModel['services'][0]['rows']) === 7 && count($runtimeModel['services'][1]['rows']) === 8, 'Both enabled cache services preserve all metrics as label/value rows.');
$runtimeHtml = TinyCat\Core\Ui\Admin\Diagnostics::render($runtime);
$check(str_contains($runtimeHtml, 'scope="row"') && str_contains($runtimeHtml, 'badge-success') && str_contains($runtimeHtml, 'badge-info') && str_contains($runtimeHtml, 'action="/api/site/cache-resets"') && str_contains($runtimeHtml, 'name="_csrf"'), 'Normalized cache tables preserve success/info badges and the authorized CSRF reset form.');
$empty = TinyCat\Support\Components::render('ui/dashboard', ['widgets' => []]);
$check(str_contains($empty, 'empty-state') && !str_contains($empty, 'data-dashboard-widget'), 'An empty registry uses the shared empty state.');
echo "PASS typed owner dashboard, bounded queries, safe rendering and failure isolation ({$checks} checks)\n";
