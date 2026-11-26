<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

define('TINYCAT', true);
require dirname(__DIR__, 3) . '/App/Core/Runtime/autoload.php';
$autoloaders = count(spl_autoload_functions());
require_once dirname(__DIR__, 3) . '/App/Core/Runtime/autoload.php';
require_once dirname(__DIR__, 3) . '/App/Core/Runtime/autoload.php';
if (count(spl_autoload_functions()) !== $autoloaders || class_exists(\TinyCat\Core\Runtime\Application::class, false) || session_status() !== PHP_SESSION_NONE) { throw new RuntimeException('Autoload entrypoints must be idempotent and must not start the application.'); }
if (($argv[1] ?? '') === '--framework') {
    $loader = \TinyCat\Core\Modules\Loader::fromSelection(dirname(__DIR__, 3), []);
    \TinyCat\Core\Runtime\Application::start(new \TinyCat\Core\Runtime\Configuration(['database' => ['driver' => 'mysql']]), $loader);
    foreach ([\TinyCat\Core\Identity\Pages::class, \TinyCat\Core\Identity\Authentication::class, \TinyCat\Core\Tasks\Pages::class, \TinyCat\Core\Tasks\Tokens::class, \TinyCat\Core\Installation\Pages::class, \TinyCat\Core\Updates\Pages::class, \TinyCat\Core\Ui\Admin\Diagnostics::class, \TinyCat\Core\Runtime\Errors::class] as $class) {
        if (class_exists($class, false)) { throw new RuntimeException('Framework registration eagerly loaded ' . $class); }
    }
    if (session_status() !== PHP_SESSION_NONE || class_exists(\TinyCat\Database\Connection::class, false)) { throw new RuntimeException('Framework registration opened runtime state.'); }
    $feed = \TinyCat\Core\Feeds\FeedParser::parse('<rss><channel><item><guid>core-only</guid><title>Story</title><description>Read more https://93.184.216.34/story</description></item></channel></rss>');
    if (($feed[0]['description'] ?? '') !== 'Read more https://93.184.216.34/story') { throw new RuntimeException('Core feed parsing must preserve editorial text without Social.'); }
    $url = 'https://youtu.be/abc123?utm_source=keep&fbclid=keep#part';
    $parsed = \TinyCat\Core\Text\LinkParser::fromRaw($url);
    if (($parsed['normalized_url'] ?? '') !== $url || isset($parsed['provider'])) { throw new RuntimeException('Core URL parsing must preserve query data without a provider policy.'); }
    if (count(\TinyCat\Core\Text\LinkParser::extract(implode(' ', array_map(static fn (int $n): string => 'https://example.com/' . $n, range(1, 12))))) !== 12) { throw new RuntimeException('Core URL extraction must not impose the Social link budget.'); }
    if (\TinyCat\Core\Text\InlineText::map(':) #tag @12', static fn (string $text): string => $text) !== ':) #tag @12') { throw new RuntimeException('Core text must not apply Social inline syntax.'); }
    $metadata = (new ReflectionMethod(\TinyCat\Core\Links\Metadata::class, 'parseHtml'))->invoke(null, '<title>Generic page</title><meta name="description" content="Read more">', 'https://93.184.216.34/');
    if ($metadata !== ['title' => 'Generic page', 'description' => 'Read more']) { throw new RuntimeException('Core metadata parsing must not apply provider or editorial rules.'); }
    foreach (get_included_files() as $included) {
        if (str_contains(str_replace('\\', '/', $included), '/App/Modules/')) { throw new RuntimeException('Core feed parsing loaded a functional module.'); }
    }
    echo "PASS framework callbacks defer page handlers, identity, diagnostics and scheduler authorization\n";
    exit;
}
if (($argv[1] ?? '') === '--social') {
    $loader = \TinyCat\Core\Modules\Loader::fromSelection(dirname(__DIR__, 3), ['tinycat.social' => 'App/Modules/Social/module.json']);
    \TinyCat\Core\Runtime\Application::start(new \TinyCat\Core\Runtime\Configuration(['database' => ['driver' => 'mysql']]), $loader);
    foreach ([\TinyCat\Social\Bots\Pages::class, \TinyCat\Social\Bots\Resource::class, \TinyCat\Social\Posts\AuthorPages::class, \TinyCat\Social\Profiles\Repository::class, \TinyCat\Social\Posts\Pages::class, \TinyCat\Social\Posts\Embed::class, \TinyCat\Social\Site\Pages::class, \TinyCat\Social\Site\Discovery::class] as $class) {
        if (class_exists($class, false)) { throw new RuntimeException('Social registration eagerly loaded ' . $class); }
    }
    if (session_status() !== PHP_SESSION_NONE || class_exists(\TinyCat\Database\Connection::class, false)) { throw new RuntimeException('Social registration opened runtime state.'); }
    echo "PASS Social registration defers pages, discovery and task details\n";
    exit;
}
$identityCalls = 0; $readyCalls = 0;
$context = new \TinyCat\Core\Runtime\Context(new \TinyCat\Core\Runtime\Configuration([
    'database' => tinycat_test_database_config(), 'i18n' => ['locale' => 'cs'], 'cache' => ['driver' => 'filesystem'],
]), identity: static function () use (&$identityCalls): array { $identityCalls++; return ['id' => 7]; }, ready: static function () use (&$readyCalls): bool { $readyCalls++; return true; });
$includedBeforeBoot = get_included_files();
$api = \TinyCat\Core\Runtime\Application::boot($context);
set_exception_handler(static function (Throwable $error): never { fwrite(STDERR, (string) $error . PHP_EOL); exit(1); });
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$assert($identityCalls === 0 && $readyCalls === 0, 'Boot must not evaluate identity or account readiness.');
$assert(!class_exists(\TinyCat\Database\Connection::class, false), 'Boot does not connect to the database.');
$assert(\TinyCat\Database\Schema::app_required_tables() === [], 'The minimal app has no business tables.');
$assert(\TinyCat\Core\Modules\Registrations::current()->entries->tasks === [], 'The minimal app has no domain tasks.');
$assert(\TinyCat\Support\Localization::locale() === 'cs', 'Locale uses the supplied configuration.');
$assert((new ReflectionMethod(\TinyCat\Support\Cache::class, 'driver'))->invoke(null) === 'filesystem', 'Cache uses supplied technical configuration.');
$assert(\TinyCat\Database\Schema::app_db_ready() && $readyCalls === 1, 'Readiness uses the supplied callback and in-memory DSN.');
$assert((int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'") === 0, 'The configured database contains no social or account tables.');
$assert(\TinyCat\Core\Text\InlineText::map('#tag @user', static fn (string $text): string => $text) === '#tag @user', 'Core renders an explicit neutral text kind without any functional owner or domain tables.');
\TinyCat\Http\Router::route('GET', '/_kernel-probe', static function (): void { echo 'kernel'; });
ob_start();
$matched = \TinyCat\Http\Router::dispatch('/_kernel-probe', 'GET');
$assert($matched && ob_get_clean() === 'kernel', 'The minimal app dispatches a technical route.');
$assert($identityCalls === 0, 'Public routing does not resolve an identity.');
$resolver = (new ReflectionProperty($api, 'currentUser'))->getValue($api);
$assert($resolver()['id'] === 7 && $identityCalls === 1, 'The API receives the supplied identity resolver.');
foreach (['TinyCat\\Social\\Profiles\\Api', 'TinyCat\\Core\\Identity\\Authentication', 'TinyCat\\Social\\Posts\\Api', 'TinyCat\\Social\\Bots\\Api', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Http\\Session'] as $class) {
    $assert(!class_exists($class, false), 'Minimal runtime did not load ' . $class);
}
$configFile = str_replace('\\', '/', dirname(__DIR__, 3) . '/config.php');
$assert(!in_array($configFile, array_map(static fn (string $file): string => str_replace('\\', '/', $file), array_diff(get_included_files(), $includedBeforeBoot)), true), 'Minimal runtime did not read the local configuration file.');
$revision = \TinyCat\Core\Modules\Resources::revision();
$policyCalls = 0;
\TinyCat\Http\Middleware::register('fixture.access', static function () use (&$policyCalls): void { $policyCalls++; });
$api->get('/_custom-policy', static fn (): array => [], 'fixture.access');
$assert($policyCalls === 0, 'Registering a custom access policy does not authorize eagerly.');
\TinyCat\Http\Middleware::authorize('fixture.access', false);
$assert($policyCalls === 1, 'Custom authorization is supplied without a core domain switch.');
try { $api->get('/_unknown-policy', static fn (): array => [], 'fixture.unknown'); throw new RuntimeException('Unknown access policy accepted.'); }
catch (LogicException) { $assert(true, 'Unknown access policy is rejected.'); }
try { \TinyCat\Core\Runtime\Application::boot(new \TinyCat\Core\Runtime\Context()); throw new RuntimeException('Duplicate boot was accepted.'); }
catch (LogicException) { $assert(\TinyCat\Core\Modules\Resources::revision() === $revision, 'Duplicate boot fails before changing shared state.'); }
try { \TinyCat\Core\Runtime\Application::start(); throw new RuntimeException('Duplicate start was accepted.'); }
catch (LogicException) { $assert(\TinyCat\Core\Modules\Resources::revision() === $revision, 'Duplicate start fails before changing shared state.'); }

// Platform services remain available when no functional module is selected.
foreach ([\TinyCat\Core\Tasks\Runner::class, \TinyCat\Core\Tasks\History::class, \TinyCat\Core\Tasks\Cleanup\Task::class, \TinyCat\Core\Mail\Smtp::class, \TinyCat\Core\Settings\Store::class] as $class) {
    $assert(class_exists($class), 'Platform implementation is available without a module: ' . $class);
}
$assert(\TinyCat\Core\Tasks\Health\Checks::scheduler()['metrics'] === [], 'Task diagnostics do not invent business tasks.');
$assert(\TinyCat\Core\Tasks\Cleanup\Files::temporary(1) === ['changed' => 0, 'has_more' => false, 'inspected' => 0], 'An empty catalog supplies no temporary directories.');
$assert(\TinyCat\Core\Mail\Smtp::email_smtp_send('invalid', 'subject', 'body') === false, 'Unconfigured SMTP fails without loading account validation.');
$assert(\TinyCat\Core\Tasks\Execution::health()['available_tasks'] === [], 'Core execution metadata does not need the Scheduler module.');
foreach (['Accounts', 'Scheduler', 'Settings', 'Mail', 'Bots', 'Status'] as $owner) {
    $prefix = '/App/Modules/' . $owner . '/';
    $assert(array_filter(get_included_files(), static fn (string $file): bool => str_contains(str_replace('\\', '/', $file), $prefix)) === [], 'Platform services did not load module files: ' . $owner);
}
$policyCalls = 0;
\TinyCat\Core\Runtime\Startup::provideHttp('fixture.http', new \TinyCat\Core\Runtime\HttpPolicy(beforeDispatch: static function (string $path) use (&$policyCalls): void { $policyCalls++; }));
$assert($policyCalls === 0, 'HTTP policy registration is lazy');
try { \TinyCat\Core\Runtime\Startup::provideHttp('fixture.conflict', new \TinyCat\Core\Runtime\HttpPolicy()); throw new RuntimeException('Conflicting HTTP policy accepted.'); }
catch (LogicException) { $assert($policyCalls === 0, 'Conflicting HTTP owners fail before dispatch'); }
$_SERVER['REQUEST_URI'] = '/_kernel-probe'; $_SERVER['REQUEST_METHOD'] = 'GET'; \TinyCat\Http\Request::reset();
ob_start(); \TinyCat\Core\Runtime\HttpKernel::respond();
$assert(ob_get_clean() === 'kernel' && $policyCalls === 1, 'Common HTTP kernel dispatches through the selected narrow policy');
\TinyCat\Http\Router::route('GET', '/_kernel-failure', static function (): void { echo 'PRIVATE_PARTIAL_OUTPUT'; throw new RuntimeException('Fixture request failure'); });
$_SERVER['REQUEST_URI'] = '/_kernel-failure'; \TinyCat\Http\Request::reset();
ob_start(); \TinyCat\Core\Runtime\HttpKernel::respond(); $failure = ob_get_clean();
$assert(http_response_code() === 500 && $failure === 'Request failed.', 'Core discards partial output before responding to a route failure');
echo 'PASS minimal runtime without accounts, social tables, settings, session or config file (' . $checks . " checks)\n";
