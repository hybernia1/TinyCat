<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../../tools/support/workspace.php';

use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Registrations;
use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\Catalog;
use TinyCat\Core\Modules\Contributions;
use TinyCat\Core\Modules\Registries;
use TinyCat\Core\Runtime\Configuration;

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/Core/Runtime/autoload.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$reject = static function (callable $action, string $message) use ($assert): void {
    try { $action(); } catch (InvalidArgumentException) { $assert(true, $message); return; }
    throw new RuntimeException($message . ' was accepted.');
};
$originCalls = 0;
$source = new \TinyCat\Core\Modules\PlayerOrigins('fixture.players', static function () use (&$originCalls): array { $originCalls++; return ['https://player.example']; });
$originBundle = new Contributions(playerOrigins: [$source]);
$originRegistry = new Registries(['fixture.one' => $originBundle, 'fixture.two' => new Contributions(playerOrigins: [new \TinyCat\Core\Modules\PlayerOrigins('fixture.other', static fn (): array => ['https://other.example', 'https://player.example'])])]);
$assert($originCalls === 0, 'Player origins remain lazy at registration');
$assert($originRegistry->owner('player-origins', 'fixture.players') === 'fixture.one', 'Player origins retain their owner');
$reject(static fn () => new Registries(['fixture.one' => $originBundle, 'fixture.two' => $originBundle]), 'Duplicate player origin contribution');
Registrations::configure($originRegistry);
$assert(\TinyCat\Core\Modules\PlayerOrigins::current() === ['https://other.example', 'https://player.example'], 'Modules merge and deduplicate player origins');
Registrations::configure(new Registries());
$assert(\TinyCat\Core\Modules\PlayerOrigins::current() === [], 'Core alone adds no external players');
foreach (["https://player.example; script-src *", 'https://*.example', 'http://example.com', 'https://example.com/path', "https://example.com\r\nX-Test: yes", 'https://user@example.com', 'https://example.com:99999'] as $invalid) {
    $reject(static fn () => \TinyCat\Core\Modules\PlayerOrigins::validate([$invalid]), 'Unsafe player origin');
}
$identityCalls = 0;
\TinyCat\Core\Identity\Integration::provide('fixture.identity', ['next' => static function (string $url) use (&$identityCalls): string { $identityCalls++; return $url; }]);
try { \TinyCat\Core\Identity\Integration::provide('fixture.conflict', ['landing' => static fn (): string => '/', 'next' => static fn (string $url): string => $url]); throw new RuntimeException('Identity collision accepted'); }
catch (LogicException) { $assert($identityCalls === 0, 'Identity registration and collisions remain lazy'); }
\TinyCat\Core\Identity\Integration::provide('tinycat.social', ['landing' => static fn (): string => '/social']);
$assert(\TinyCat\Core\Identity\Integration::landing(null) === '/social', 'Failed identity registration does not claim earlier keys');
foreach (['registration', 'url', 'path', 'privacy', 'image', 'render', 'not_found'] as $slot) {
    try { \TinyCat\Core\Identity\Integration::provide('fixture.retired', [$slot => static fn (): string => 'unused']); throw new RuntimeException('Retired Identity slot accepted'); }
    catch (InvalidArgumentException) { $assert(true, 'Retired account extension is rejected: ' . $slot); }
}
\TinyCat\Core\Identity\Integration::configure([]);
\TinyCat\Core\Security\RateLimitPolicy::defineRequest('fixture.one', 'fixture-budget', ['scope' => 'account', 'window' => 60, 'max' => 2]);
try { \TinyCat\Core\Security\RateLimitPolicy::defineRequest('fixture.two', 'fixture-budget', ['scope' => 'ip', 'window' => 1, 'max' => 100]); throw new RuntimeException('Budget collision accepted'); }
catch (LogicException) { $assert(\TinyCat\Core\Security\RateLimitPolicy::requestRule('fixture-budget')['max'] === 2, 'Conflicting budget owner cannot replace policy'); }
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
Registrations::configure($registries);
$healthCapability = $modules->catalog->get('tinycat.health');
$assert($healthCapability->providers === [] && $healthCapability->resources === [], 'The signed Health capability is metadata only, with no independent provider or assets');
$assert($registries->owner('task', 'site-health') === 'tinycat.scheduler' && $registries->owner('task', 'cleanup') === 'tinycat.scheduler', 'Tasks owns both built-in tasks without a separate Health service');
foreach (['TinyCat\\Support\\Config', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Database\\Connection', 'TinyCat\\Http\\Session'] as $class) {
    $assert(!class_exists($class, false), 'Registry collection did not load ' . $class);
}
$participant = \TinyCat\Social\Module::accountLifecycle();
$assert($participant instanceof \TinyCat\Core\Identity\Participant, 'Social lifecycle callback can be assembled without SQL');
$assert(\TinyCat\Social\Module::fileReferences('unknown', ['candidate']) === [] && \TinyCat\Social\Module::fileReferences('link-images', []) === [], 'Empty or unrelated reference lookups do not load the database');
$assert(!class_exists(\TinyCat\Database\Connection::class, false), 'Lifecycle and empty file references remain lazy');
$assert(session_status() === PHP_SESSION_NONE, 'Registry collection did not open a session');
$assert(array_map(static fn ($entry): string => $entry->id, $registries->entries->tasks) === ['feeds', 'cleanup', 'site-health'], 'Existing task IDs and order remain stable');
$assert(array_map(static fn ($entry): string => $entry->id, $registries->entries->health) === ['http', 'database', 'cache', 'storage', 'uploads', 'disk', 'runtime', 'scheduler', 'updates'], 'Existing probe IDs and order remain stable');
$maintenanceIds = array_map(static fn ($entry): string => $entry->id, $registries->entries->maintenance);
$assert(array_values(array_diff($maintenanceIds, ['orphan_branding_files', 'retired_build_files', 'retired_runtime_assets', 'expired_prepared_assets', 'abandoned_module_packages', 'old_debug_logs'])) === [
    'orphan_terms', 'orphan_links', 'orphan_status_image_files', 'orphan_link_image_files', 'orphan_avatar_files', 'temporary_files', 'update_files', 'expired_cache_files',
    'old_action_limits', 'old_password_reset_tokens', 'old_read_notifications', 'old_scheduler_runs', 'bot_history', 'duplicate_bot_posts',
], 'Existing maintenance IDs and order remain stable');
$assert(in_array('orphan_branding_files', $maintenanceIds, true) && $registries->owner('maintenance', 'orphan_branding_files') === 'tinycat.settings', 'Branding adds one owner-declared orphan task');
$assert(in_array('retired_build_files', $maintenanceIds, true) && $registries->owner('maintenance', 'retired_build_files') === 'tinycat.updates', 'Core contributes lazy release build retirement without a module domain dependency');
foreach (['retired_runtime_assets' => 'tinycat.ui', 'abandoned_module_packages' => 'tinycat.updates', 'old_debug_logs' => 'tinycat.scheduler'] as $id => $owner) { $assert(in_array($id, $maintenanceIds, true) && $registries->owner('maintenance', $id) === $owner, 'Technical maintenance is registered lazily: ' . $id); }
$assert($registries->task('cleanup')->options === ['cleanup_batch' => 500] && $registries->task('cleanup')->adminOptions === ['cleanup_batch' => 5000], 'Cleanup owns distinct scheduled and manual defaults');
$assert($registries->initialMaintenance() === 'update_files', 'The existing initial cleanup cursor is preserved by its owner');
$assert($registries->owner('task', 'feeds') === 'tinycat.social' && $registries->owner('maintenance', 'old_password_reset_tokens') === 'tinycat.accounts', 'Tasks and retention have explicit owners');
$assert($registries->task('feeds')->options === ['bot_limit' => 20], 'Feed defaults remain stable');
foreach (['task' => 'feeds', 'maintenance' => 'bot_history', 'sitemap' => 'authors', 'embed' => 'social.posts', 'player-origins' => 'social.players', 'widget' => 'social.comments'] as $kind => $id) {
    $assert($registries->owner($kind, $id) === 'tinycat.social', 'Social contributions retain one module owner: ' . $kind . ':' . $id);
}
$reject(static fn () => new Registries(['tinycat.social' => new Contributions(tasks: [$registries->task('feeds'), $registries->task('feeds')])]), 'Duplicate contributions within one owner are rejected by core');

$bundle = new Contributions(tasks: [$registries->task('feeds')]);
$lookupCalls = 0;
$lookup = new \TinyCat\Core\Modules\ComponentLookup('fixture.lookup', static function () use (&$lookupCalls): array { $lookupCalls++; return []; });
$lookupBundle = new Contributions(componentLookups: [$lookup]);
$reject(static fn () => new Registries(['fixture.one' => $lookupBundle, 'fixture.two' => $lookupBundle]), 'Duplicate component lookup IDs across owners');
$assert($lookupCalls === 0, 'Component lookup registrations do not evaluate presentation builders');
$reject(static fn () => new Registries(['fixture.one' => $bundle, 'fixture.two' => $bundle]), 'Duplicate task IDs across owners');
foreach (array_keys(get_object_vars($registries->entries)) as $kind) {
    if (in_array($kind, ['configOverrides', 'mailDelivery', 'notificationInbox'], true) || $registries->entries->$kind === []) { continue; }
    $entries = $registries->entries->$kind;
    $duplicate = new Contributions(...[$kind => [$entries[0], $entries[0]]]);
    $reject(static fn () => new Registries(['fixture.owner' => $duplicate]), 'Duplicate ' . $kind . ' IDs within one owner');
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
$source = new \TinyCat\Core\Modules\SearchSource('fixture', static function () use (&$discoveryCalls): string { $discoveryCalls++; return 'Fixture'; }, static function () use (&$discoveryCalls): array { $discoveryCalls++; return ['items' => [], 'cursor' => []]; }, render: static function () use (&$discoveryCalls): string { $discoveryCalls++; return ''; });
$section = new \TinyCat\Core\Modules\SitemapSection('fixture', static function () use (&$discoveryCalls): int { $discoveryCalls++; return 0; }, static function () use (&$discoveryCalls): array { $discoveryCalls++; return []; });
$reject(static fn () => new \TinyCat\Core\Modules\SitemapSection('index', $section->count, $section->entries), 'The sitemap index sentinel cannot be registered as a section');
$assert($discoveryCalls === 0 && \TinyCat\Core\Discovery\Sitemap::url() === '/sitemap.xml' && \TinyCat\Core\Discovery\Sitemap::url('fixture', 2) === '/sitemap-fixture-2.xml', 'Sitemap definition validation stays lazy and section URLs remain distinct from the index');
$discovery = new Contributions(search: [$source], sitemaps: [$section]);
$metadata = new Registries(['fixture.owner' => $discovery]);
$assert($discoveryCalls === 0 && $metadata->owner('search', 'fixture') === 'fixture.owner' && $metadata->owner('sitemap', 'fixture') === 'fixture.owner', 'Discovery callbacks retain their owners and do not execute during registration');
$reject(static fn () => new Registries(['fixture.one' => $discovery, 'fixture.two' => $discovery]), 'Duplicate discovery owners');
$reverse = new Registries(['fixture.owner' => new Contributions(tasks: array_reverse($registries->entries->tasks))]);
$assert(array_map(static fn ($entry): string => $entry->id, $reverse->entries->tasks) === ['feeds', 'cleanup', 'site-health'], 'Contributor ordering cannot change declared task order');

// Bind only test configuration; never open the real installation database.
\TinyCat\Support\Config::configure(new Configuration(['database' => tinycat_test_database_config(), 'cache' => ['driver' => 'filesystem']]));
$assert((new ReflectionMethod(\TinyCat\Support\Cache::class, 'driver'))->invoke(null) === 'filesystem' && !class_exists(\TinyCat\Core\Settings\Store::class, false), 'Technical cache configuration does not load Settings');
\TinyCat\Support\Localization::locale('en');
$menu = \TinyCat\Core\Ui\Admin\Navigation::administration();
$assert(count($menu) === 8 && $menu[0]['href'] === '/admin' && $menu[1]['href'] === '/admin/users' && $menu[7]['modal_url'] === '/api/settings/editor', 'Navigation retains core module management and one shared task page');
$assert(in_array('/admin/modules', array_column($menu, 'href'), true), 'Core module management is present in administration');
\TinyCat\Support\Localization::locale('cs');
$assert(\TinyCat\Core\Ui\Admin\Navigation::administration()[1]['label'] !== $menu[1]['label'], 'Navigation labels resolve in the current language at render time');
\TinyCat\Database\Connection::exec('CREATE TABLE scheduler_runs (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, task TEXT, status VARCHAR(24), started_at DATETIME)');
\TinyCat\Database\Connection::exec('INSERT INTO scheduler_runs (task, status, started_at) VALUES (?, ?, ?)', ['cleanup', 'completed', date('Y-m-d H:i:s')]);
$withoutBots = new Catalog(array_values(array_filter($modules->catalog->definitions(), static fn ($definition): bool => $definition->id !== 'tinycat.social')));
Resources::withCatalog($withoutBots, static function () use ($assert): void {
    $assert(!isset(\TinyCat\Core\Tasks\Tasks::definitions()['feeds']), 'Removing Bots removes its task');
    $assert(!in_array('bot_history', \TinyCat\Core\Tasks\Cleanup\Task::taskIds(), true), 'Removing Bots removes its maintenance');
    $assert(count(\TinyCat\Core\Ui\Admin\Navigation::administration()) === 7, 'Removing Social retains only framework navigation including the dashboard');
    $assert(\TinyCat\Core\Tasks\Health\Checks::scheduler()['metrics'] === ['cleanup' => 'ok'], 'Scheduler diagnostics work without bot tables when Bots is absent');
});
$assert(isset(\TinyCat\Core\Tasks\Tasks::definitions()['feeds']), 'Leaving a selection scope restores registrations');
$fingerprint = Resources::fingerprint();
Resources::withCatalog(new Catalog([]), static function () use ($assert, $fingerprint): void {
    $assert(Resources::fingerprint() !== $fingerprint, 'A scoped selection invalidates the cached fingerprint');
    $assert(\TinyCat\Core\Tasks\Tasks::definitions() === [] && \TinyCat\Core\Ui\Admin\Navigation::administration() === [] && \TinyCat\Core\Tasks\Health\Checks::definitions() === [] && \TinyCat\Core\Tasks\Cleanup\Task::taskIds() === [], 'An empty selection has no domain contributions');
});
$assert(Resources::fingerprint() === $fingerprint, 'Leaving a scope restores the fingerprint of the original selection');

$temporary = \TinyCat\Tools\Workspace::directory('tests/tmp') . '/registry-provider-' . bin2hex(random_bytes(6));
mkdir($temporary . '/App/Modules/RegistryFixture/src', 0700, true);
copy($root . '/tests/fixtures/modules/registry-provider.php', $temporary . '/App/Modules/RegistryFixture/src/Module.php');
file_put_contents($temporary . '/App/Modules/RegistryFixture/module.json', json_encode(['format' => 1, 'coreApi' => 1, 'id' => 'fixture.provider', 'httpNamespace' => 'registry-fixture', 'namespace' => 'TinyCat\\RegistryFixture\\', 'providers' => ['routes' => 'TinyCat\\RegistryFixture\\Module', 'contributions' => 'TinyCat\\RegistryFixture\\Module']], JSON_THROW_ON_ERROR));
try {
    $fixture = Loader::fromSelection($temporary, ['fixture.provider' => \TinyCat\RegistryFixture\Module::class]);
    $selection = new Catalog([$fixture->catalog->get('fixture.provider')]);
    $collected = $fixture->registries($selection);
    $assert($collected->owner('task', 'fixture.task') === 'fixture.provider', 'A selected provider contributes without editing a central list');
    $ownership = new ReflectionProperty(\TinyCat\Core\Modules\Providers::class, 'ownership');
    $definition = $selection->get('fixture.provider');
    \TinyCat\Core\Modules\Providers::duringStartup($selection, static function () use ($selection, $definition, $ownership, $assert, $reject, $root): void {
        \TinyCat\Core\Modules\Providers::validateAll($selection);
        $cached = $ownership->getValue();
        $assert(count($cached) === 1, 'Startup caches successful ownership by definition identity');
        $reject(static fn () => \TinyCat\Core\Modules\Providers::checked($definition, 'routes', \TinyCat\Core\Modules\ProvidesSchemas::class), 'A cached provider still has to implement the requested interface');
        \TinyCat\Core\Modules\Providers::duringStartup(new Catalog([]), static function () use ($ownership, $cached, $assert): void {
            $assert($ownership->getValue() !== $cached && count($ownership->getValue()) === 0, 'A different catalog receives a separate startup cache');
        });
        $assert($ownership->getValue() === $cached, 'Leaving a nested catalog restores the outer cache');
        Resources::withCatalog($selection, static function () use ($selection, $ownership, $cached, $assert): void {
            \TinyCat\Core\Modules\Providers::validateAll($selection);
            $assert($ownership->getValue() !== $cached, 'A selection revision invalidates successful ownership checks');
        });
        $foreign = \TinyCat\Core\Modules\Definition::fromMetadata((object) ['format' => 1, 'coreApi' => 1, 'id' => 'fixture.provider', 'httpNamespace' => 'registry-fixture', 'namespace' => 'TinyCat\\RegistryFixture\\', 'providers' => (object) ['routes' => \TinyCat\RegistryFixture\Module::class]], $root . '/App/Core');
        $reject(static fn () => \TinyCat\Core\Modules\Providers::checked($foreign, 'routes', \TinyCat\Core\Modules\Module::class), 'The same ID with a foreign source root cannot reuse ownership');
    });
    $assert($ownership->getValue() === null, 'Startup ownership results do not survive the startup scope');
    try { \TinyCat\Core\Modules\Providers::duringStartup($selection, static function (): never { throw new RuntimeException('Scope fixture'); }); }
    catch (RuntimeException) { $assert($ownership->getValue() === null, 'A failed startup discards its validation cache'); }
    $product = 'fixture.product';
    $plan = ['format' => 3, 'framework' => \TinyCat\Core\Runtime\Framework::CAPABILITIES, 'schemaOwners' => [], 'product' => &$product, 'legacy' => false, 'selection' => [], 'active' => [], 'required' => [], 'requires' => [], 'schemas' => []];
    \TinyCat\Core\Modules\Distribution::validate($plan);
    $invalidPlan = $plan;
    $invalidPlan['framework'][] = 'fixture.unknown';
    try { \TinyCat\Core\Modules\Distribution::validate($invalidPlan); throw new LogicException('Unknown framework accepted'); }
    catch (RuntimeException) { $assert(true, 'Unknown framework capabilities remain rejected'); }
    $product = 'INVALID'; $invalid = false;
    try { \TinyCat\Core\Modules\Distribution::validate($plan); } catch (RuntimeException) { $invalid = true; }
    $assert($invalid, 'Mutating a referenced descriptor value cannot mutate its successful validation snapshot');
    $consumerSelection = new Catalog([$fixture->catalog->get('fixture.provider'), ...array_values(array_filter($modules->catalog->definitions(), static fn ($definition): bool => in_array($definition->id, ['tinycat.security', 'tinycat.accounts', 'tinycat.notifications', 'tinycat.settings', 'tinycat.scheduler'], true)))]);
    Resources::withCatalog($consumerSelection, static function () use ($collected, $assert, $reject): void {
        Registrations::configure($collected);
        $assert(\TinyCat\Core\Tasks\Tasks::definitions()['fixture.task']['admin']['title'] === 'fixture.task', 'A task without custom admin labels still has safe display metadata');
        $context = \TinyCat\Core\Tasks\Tasks::context('fixture.task', ['mode' => 'admin', 'options' => ['batch' => '-2', 'target' => '7']]);
        $assert($context['options'] === ['batch' => 1, 'target' => 7], 'A new task owns its normalization and administrator-only parameters');
        $reject(static fn () => \TinyCat\Core\Tasks\Tasks::context('fixture.task', ['mode' => 'http', 'options' => ['target' => 7]]), 'Administrative options on HTTP execution');
        $reject(static fn () => \TinyCat\Core\Tasks\Execution::contexts('all', ['target' => 7], 'admin'), 'Targeted options on all-task execution');
        $assert(isset(\TinyCat\Core\Tasks\Health\Checks::definitions()['fixture.probe']), 'A new provider contributes a health probe without running it');
        $assert(\TinyCat\Core\Tasks\Cleanup\Task::taskIds() === ['fixture.cleanup'], 'A new provider contributes maintenance without running it');
    });
} finally { unlink($temporary . '/App/Modules/RegistryFixture/src/Module.php'); unlink($temporary . '/App/Modules/RegistryFixture/module.json'); rmdir($temporary . '/App/Modules/RegistryFixture/src'); rmdir($temporary . '/App/Modules/RegistryFixture'); rmdir($temporary . '/App/Modules'); rmdir($temporary . '/App'); rmdir($temporary); }

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/modules/minimal-boot.php') . ' 2>&1', $output, $exitCode);
if ($exitCode !== 0) { throw new RuntimeException('Minimal runtime failed: ' . implode("\n", $output)); }
echo implode("\n", $output) . "\n";

// An unknown owner needs no client registration and contributes only lazily.
$savedRegistries = \TinyCat\Core\Modules\Registrations::current();
$settingsCalls = 0;
$settingsEntry = new \TinyCat\Core\Modules\SettingsSection('fixture.settings', 'fixture', static function () use (&$settingsCalls): array {
    $settingsCalls++;
    return ['label' => 'Fixture "<section>"', 'icon' => 'settings', 'fields' => [
        ['key' => 'fixture.secret', 'label' => 'Secret <field>', 'type' => 'password', 'default' => 'private-default', 'value' => 'private-value', 'normalize' => static fn (mixed $value): mixed => $value],
    ]];
});
try {
    \TinyCat\Core\Modules\Registrations::configure(new Registries(['fixture.owner' => new Contributions(settings: [$settingsEntry])]));
    $assert($settingsCalls === 0, 'Settings registration never evaluates the owner callback');
    $presentation = \TinyCat\Core\Settings\Sections::presentation();
    $assert($presentation['sections']['fixture']['label'] === 'Fixture "<section>"' && $presentation['fields']['fixture.secret'] === ['label' => 'Secret <field>'], 'Unknown owners derive presentation without client declarations or private data');
    $encoded = json_encode($presentation, JSON_THROW_ON_ERROR);
    $assert(!str_contains($encoded, 'private-') && !str_contains($encoded, 'normalize'), 'Presentation excludes values, defaults and operational callbacks');
    foreach ([['label' => 'Missing icon', 'fields' => []], ['label' => 'Section', 'icon' => 'settings', 'fields' => [['key' => 'fixture.missing', 'type' => 'text']]]] as $invalid) {
        try { \TinyCat\Core\Settings\Sections::presentation(['fixture' => $invalid]); throw new RuntimeException('Incomplete settings presentation was accepted'); }
        catch (LogicException $error) { $assert(str_contains($error->getMessage(), 'fixture'), 'Incomplete presentation identifies the section or field owner'); }
    }
    \TinyCat\Core\Modules\Registrations::configure(new Registries());
    $emptyPresentation = \TinyCat\Core\Settings\Sections::presentation();
    $assert($emptyPresentation['sections'] === [] && $emptyPresentation['fields'] === [], 'Removing the owner removes its settings presentation');
} finally { \TinyCat\Core\Modules\Registrations::configure($savedRegistries); }
$assert($exitCode === 0, 'The minimal runtime works in its own clean process');

$output = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/modules/minimal-boot.php') . ' --framework 2>&1', $output, $exitCode);
if ($exitCode !== 0) { throw new RuntimeException('Framework startup failed: ' . implode("\n", $output)); }
echo implode("\n", $output) . "\n";
$assert($exitCode === 0, 'Framework registration keeps operational callbacks lazy');
echo 'PASS offline registries, ownership, selection and technical startup (' . $checks . " checks)\n";

$output = []; $exitCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/modules/minimal-boot.php') . ' --social 2>&1', $output, $exitCode);
$assert($exitCode === 0, 'Social registration stays lazy: ' . implode("\n", $output));
echo implode("\n", $output) . "\n";

$configuration = new Configuration(['literal.key' => null, 'nested' => ['value' => 7], 'app' => ['debug' => 'off']]);
\TinyCat\Support\Config::configure($configuration);
$assert(\TinyCat\Support\Config::snapshot() === $configuration, 'Configuration reads retain the supplied immutable snapshot.');
$assert(\TinyCat\Support\Config::get('nested.value') === 7 && \TinyCat\Support\Config::get('literal.key', 'fallback') === null, 'Configuration keeps dotted lookup and explicit null semantics.');
$values = \TinyCat\Support\Config::get(); $values['nested']['value'] = 9;
$assert(\TinyCat\Support\Config::get('nested.value') === 7, 'Returned values cannot mutate the configuration snapshot.');
\TinyCat\Support\Config::configure(new Configuration(['nested' => ['value' => 11]]));
\TinyCat\Support\Config::load();
$assert(\TinyCat\Support\Config::get('nested.value') === 11 && $configuration->get('nested.value') === 7, 'Reconfiguration replaces the current snapshot and load preserves it without changing old snapshots.');

echo "PASS immutable configuration reuse, replacement and value isolation\n";
