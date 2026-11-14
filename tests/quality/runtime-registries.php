<?php
declare(strict_types=1);

use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Registrations;
use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\Catalog;
use TinyCat\Core\Modules\Contributions;
use TinyCat\Core\Modules\Registries;
use TinyCat\Core\Runtime\Configuration;

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/autoload.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$reject = static function (callable $action, string $message) use ($assert): void {
    try { $action(); } catch (InvalidArgumentException) { $assert(true, $message); return; }
    throw new RuntimeException($message . ' was accepted.');
};
$configuration = new Configuration(['key.path' => 'literal', 'key' => ['path' => 'nested'], 'null' => null, 'zero' => 0]);
$assert($configuration->get('key.path') === 'literal', 'Literal dotted keys retain precedence');
$assert($configuration->get('null', 'fallback') === null && $configuration->get('zero') === 0, 'Null and zero are not missing values');
$assert($configuration->get('missing.deep', 'fallback') === 'fallback', 'Missing nested keys retain fallback behavior');
$values = ['nested' => ['value' => 'before']];
$snapshot = new Configuration($values);
$values['nested']['value'] = 'after';
$assert($snapshot->get('nested.value') === 'before', 'A configuration snapshot does not observe later input mutations');
$modules = Loader::load($root);
$registries = $modules->registries();
foreach (['TinyCat\\Support\\Config', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Database\\Connection', 'TinyCat\\Http\\Session'] as $class) {
    $assert(!class_exists($class, false), 'Registry collection did not load ' . $class);
}
$assert(session_status() === PHP_SESSION_NONE, 'Registry collection did not open a session');
$assert(array_map(static fn ($entry): string => $entry->id, $registries->entries->tasks) === ['feeds', 'cleanup', 'site-health'], 'Existing task IDs and order remain stable');
$assert(array_map(static fn ($entry): string => $entry->id, $registries->entries->health) === ['http', 'database', 'cache', 'storage', 'uploads', 'disk', 'runtime', 'scheduler', 'updates'], 'Existing probe IDs and order remain stable');
$assert(array_map(static fn ($entry): string => $entry->id, $registries->entries->maintenance) === [
    'orphan_terms', 'orphan_links', 'orphan_status_image_files', 'orphan_link_image_files', 'orphan_avatar_files', 'temporary_files', 'update_files', 'expired_cache_files',
    'old_action_limits', 'old_password_reset_tokens', 'old_read_notifications', 'old_scheduler_runs', 'bot_history', 'duplicate_bot_posts',
], 'Existing maintenance IDs and order remain stable');
$assert($registries->initialMaintenance() === 'update_files', 'The existing initial cleanup cursor is preserved by its owner');
$assert($registries->owner('task', 'feeds') === 'tinycat.social' && $registries->owner('maintenance', 'old_password_reset_tokens') === 'tinycat.accounts', 'Tasks and retention have explicit owners');
$assert($registries->task('feeds')->options === ['bot_limit' => 20], 'Feed defaults remain stable');
$bundle = new Contributions(tasks: [$registries->task('feeds')]);
$reject(static fn () => new Registries(['fixture.one' => $bundle, 'fixture.two' => $bundle]), 'Duplicate task IDs across owners');
foreach (['navigation', 'health', 'maintenance', 'localizedRoutes', 'information', 'widgets', 'embeds'] as $kind) {
    $entries = $registries->entries->$kind;
    $bundle = new Contributions(...[$kind => [$entries[0]]]);
    $reject(static fn () => new Registries(['fixture.one' => $bundle, 'fixture.two' => $bundle]), 'Duplicate ' . $kind . ' IDs across owners');
}
$reject(static fn () => $registries->task('missing'), 'Unknown task lookup');
$calls = 0;
$temporary = new \TinyCat\Core\Modules\TemporaryDirectory('fixture.temporary', static function () use (&$calls): string { $calls++; return '/not-resolved-during-registration'; });
$bundle = new Contributions(temporaryDirectories: [$temporary], configOverrides: ['fixture.option']);
$metadata = new Registries(['fixture.owner' => $bundle]);
$assert($calls === 0 && $metadata->owner('temporary', 'fixture.temporary') === 'fixture.owner' && $metadata->owner('config', 'fixture.option') === 'fixture.owner', 'Temporary paths and config policies have owners and remain lazy');
$reject(static fn () => new Registries(['fixture.one' => $bundle, 'fixture.two' => $bundle]), 'Duplicate temporary directories');
$reject(static fn () => new Registries(['fixture.one' => new Contributions(configOverrides: ['fixture.option']), 'fixture.two' => new Contributions(configOverrides: ['fixture.option'])]), 'Duplicate config override owners');
$reject(static fn () => new Registries(['fixture.owner' => new Contributions(configOverrides: ['../invalid'])]), 'Invalid config override key');
$presentationCalls = 0;
$render = static function () use (&$presentationCalls): string { $presentationCalls++; return 'fixture'; };
$metadata = new Registries(['fixture.owner' => new Contributions(
    information: [new \TinyCat\Core\Modules\InformationSection('fixture.privacy', 'privacy', $render)],
    widgets: [new \TinyCat\Core\Modules\DashboardWidget('fixture.dashboard', $render)],
)]);
$assert($presentationCalls === 0 && $metadata->owner('widget', 'fixture.dashboard') === 'fixture.owner' && $metadata->owner('information', 'fixture.privacy') === 'fixture.owner', 'Presentation contributions retain ownership without evaluating runtime callbacks');
$reject(static fn () => new \TinyCat\Core\Modules\LocalizedRoute('fixture.invalid', '['), 'Malformed localized route pattern');
$reject(static fn () => new \TinyCat\Core\Modules\LocalizedRoute('fixture.invalid', '^/$', ['draft[]']), 'Nested localized query parameter');
$embedCalls = 0;
$metadata = new Registries(['fixture.owner' => new Contributions(embeds: [new \TinyCat\Core\Modules\EmbedSource('fixture.embed', static function (string $path, int $width, int $height) use (&$embedCalls): ?array { $embedCalls++; return null; })])]);
$assert($embedCalls === 0 && $metadata->owner('embed', 'fixture.embed') === 'fixture.owner', 'Embed sources declare ownership without resolving or reading data during registration');
$notificationCalls = 0;
$type = new \TinyCat\Core\Modules\NotificationType('fixture.notice', static function (array $rows) use (&$notificationCalls): array { $notificationCalls++; return $rows; }, static fn (array $row): string => '/fixture');
$bundle = new Contributions(notificationTypes: [$type], notificationInbox: true);
$metadata = new Registries(['fixture.owner' => $bundle]);
$assert($notificationCalls === 0 && $metadata->owner('notification', 'fixture.notice') === 'fixture.owner', 'Notification providers are lazy and own their stable type');
$reject(static fn () => new Registries(['fixture.one' => $bundle, 'fixture.two' => $bundle]), 'Duplicate inbox providers');
$reject(static fn () => new Registries(['fixture.one' => new Contributions(notificationTypes: [$type]), 'fixture.two' => new Contributions(notificationTypes: [$type])]), 'Duplicate notification types');
$discoveryCalls = 0;
$source = new \TinyCat\Core\Modules\SearchSource('fixture', static function () use (&$discoveryCalls): string { $discoveryCalls++; return 'Fixture'; }, static function () use (&$discoveryCalls): array { $discoveryCalls++; return ['items' => [], 'cursor' => []]; });
$section = new \TinyCat\Core\Modules\SitemapSection('fixture', static function () use (&$discoveryCalls): int { $discoveryCalls++; return 0; }, static function () use (&$discoveryCalls): array { $discoveryCalls++; return []; });
$discovery = new Contributions(search: [$source], sitemaps: [$section]);
$metadata = new Registries(['fixture.owner' => $discovery]);
$assert($discoveryCalls === 0 && $metadata->owner('search', 'fixture') === 'fixture.owner' && $metadata->owner('sitemap', 'fixture') === 'fixture.owner', 'Discovery callbacks retain their owners and do not execute during registration');
$reject(static fn () => new Registries(['fixture.one' => $discovery, 'fixture.two' => $discovery]), 'Duplicate discovery owners');
$reverse = new Registries(['fixture.owner' => new Contributions(tasks: array_reverse($registries->entries->tasks))]);
$assert(array_map(static fn ($entry): string => $entry->id, $reverse->entries->tasks) === ['feeds', 'cleanup', 'site-health'], 'Contributor ordering cannot change declared task order');

// Bind only test configuration; never open the real installation database.
\TinyCat\Support\Config::configure(new Configuration(['database' => ['dsn' => 'sqlite::memory:'], 'cache' => ['driver' => 'filesystem']]));
$assert((new ReflectionMethod(\TinyCat\Support\Cache::class, 'driver'))->invoke(null) === 'filesystem' && !class_exists(\TinyCat\Core\Settings\Store::class, false), 'Technical cache configuration does not load Settings');
\TinyCat\Support\Localization::locale('en');
$menu = \TinyCat\Social\Presentation\Navigation::administration();
$assert(count($menu) === 9 && $menu[0]['href'] === '/admin' && $menu[1]['href'] === '/admin/users' && $menu[8]['modal_url'] === '/api/settings/editor', 'Existing navigation shape is preserved alongside core module management');
$assert(in_array('/admin/modules', array_column($menu, 'href'), true), 'Core module management is present in administration');
\TinyCat\Support\Localization::locale('cs');
$assert(\TinyCat\Social\Presentation\Navigation::administration()[1]['label'] !== $menu[1]['label'], 'Navigation labels resolve in the current language at render time');
\TinyCat\Database\Connection::exec('CREATE TABLE scheduler_runs (id INTEGER PRIMARY KEY, task TEXT, status TEXT, started_at TEXT)');
\TinyCat\Database\Connection::exec('INSERT INTO scheduler_runs (task, status, started_at) VALUES (?, ?, ?)', ['cleanup', 'completed', date('Y-m-d H:i:s')]);
$withoutBots = new Catalog(array_values(array_filter($modules->catalog->definitions(), static fn ($definition): bool => $definition->id !== 'tinycat.social')));
Resources::withCatalog($withoutBots, static function () use ($assert): void {
    $assert(!isset(\TinyCat\Core\Tasks\Tasks::definitions()['feeds']), 'Removing Bots removes its task');
    $assert(!in_array('bot_history', \TinyCat\Core\Tasks\Cleanup::cron_cleanup_tasks(), true), 'Removing Bots removes its maintenance');
    $assert(count(\TinyCat\Core\Ui\Admin\Navigation::administration()) === 7, 'Removing Social retains only framework navigation including the dashboard');
    $assert(\TinyCat\Core\Tasks\HealthCheck::run()['metrics'] === ['cleanup' => 'ok'], 'Scheduler diagnostics work without bot tables when Bots is absent');
});
$assert(isset(\TinyCat\Core\Tasks\Tasks::definitions()['feeds']), 'Leaving a selection scope restores registrations');
Resources::withCatalog(new Catalog([]), static function () use ($assert): void {
    $assert(\TinyCat\Core\Tasks\Tasks::definitions() === [] && \TinyCat\Social\Presentation\Navigation::administration() === [] && \TinyCat\Core\Health\Checks::definitions() === [] && \TinyCat\Core\Tasks\Cleanup::cron_cleanup_tasks() === [], 'An empty selection has no domain contributions');
});

$temporary = $root . '/storage/registry-provider-' . bin2hex(random_bytes(6));
mkdir($temporary . '/App/Modules/RegistryFixture/src', 0700, true);
copy($root . '/tests/fixtures/modules/registry-provider.php', $temporary . '/App/Modules/RegistryFixture/src/Module.php');
file_put_contents($temporary . '/App/Modules/RegistryFixture/module.json', json_encode(['format' => 1, 'coreApi' => 1, 'id' => 'fixture.provider', 'namespace' => 'TinyCat\\RegistryFixture\\', 'providers' => ['routes' => 'TinyCat\\RegistryFixture\\Module', 'contributions' => 'TinyCat\\RegistryFixture\\Module']], JSON_THROW_ON_ERROR));
try {
    $fixture = Loader::fromSelection($temporary, ['fixture.provider' => \TinyCat\RegistryFixture\Module::class]);
    $selection = new Catalog([$fixture->catalog->get('fixture.provider')]);
    $collected = $fixture->registries($selection);
    $assert($collected->owner('task', 'fixture.task') === 'fixture.provider', 'A selected provider contributes without editing a central list');
    $consumerSelection = new Catalog([$fixture->catalog->get('fixture.provider'), ...array_values(array_filter($modules->catalog->definitions(), static fn ($definition): bool => in_array($definition->id, ['tinycat.security', 'tinycat.accounts', 'tinycat.notifications', 'tinycat.settings', 'tinycat.scheduler', 'tinycat.health'], true)))]);
    Resources::withCatalog($consumerSelection, static function () use ($collected, $assert, $reject): void {
        Registrations::configure($collected);
        $assert(\TinyCat\Core\Tasks\Tasks::definitions()['fixture.task']['admin']['title'] === 'fixture.task', 'A task without custom admin labels still has safe display metadata');
        $context = \TinyCat\Core\Tasks\Tasks::context('fixture.task', ['mode' => 'admin', 'options' => ['batch' => '-2', 'target' => '7']]);
        $assert($context['options'] === ['batch' => 1, 'target' => 7], 'A new task owns its normalization and administrator-only parameters');
        $reject(static fn () => \TinyCat\Core\Tasks\Tasks::context('fixture.task', ['mode' => 'http', 'options' => ['target' => 7]]), 'Administrative options on HTTP execution');
        $reject(static fn () => \TinyCat\Core\Tasks\Execution::contexts('all', ['target' => 7], 'admin'), 'Targeted options on all-task execution');
        $assert(isset(\TinyCat\Core\Health\Checks::definitions()['fixture.probe']), 'A new provider contributes a health probe without running it');
        $assert(\TinyCat\Core\Tasks\Cleanup::cron_cleanup_tasks() === ['fixture.cleanup'], 'A new provider contributes maintenance without running it');
    });
} finally { unlink($temporary . '/App/Modules/RegistryFixture/src/Module.php'); unlink($temporary . '/App/Modules/RegistryFixture/module.json'); rmdir($temporary . '/App/Modules/RegistryFixture/src'); rmdir($temporary . '/App/Modules/RegistryFixture'); rmdir($temporary . '/App/Modules'); rmdir($temporary . '/App'); rmdir($temporary); }

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/modules/minimal-boot.php') . ' 2>&1', $output, $exitCode);
if ($exitCode !== 0) { throw new RuntimeException('Minimal runtime failed: ' . implode("\n", $output)); }
echo implode("\n", $output) . "\n";
$assert($exitCode === 0, 'The minimal runtime works in its own clean process');
echo 'PASS offline registries, ownership, selection and technical startup (' . $checks . " checks)\n";
