<?php
declare(strict_types=1);

define('TINYCAT', true);
require dirname(__DIR__, 3) . '/App/autoload.php';
$identityCalls = 0; $readyCalls = 0;
$context = new \TinyCat\Core\Runtime\Context(new \TinyCat\Core\Runtime\Configuration([
    'database' => ['dsn' => 'sqlite::memory:'], 'i18n' => ['locale' => 'cs'], 'cache' => ['driver' => 'filesystem'],
]), identity: static function () use (&$identityCalls): array { $identityCalls++; return ['id' => 7]; }, ready: static function () use (&$readyCalls): bool { $readyCalls++; return true; });
$api = \TinyCat\Core\Runtime\Application::boot($context);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$assert($identityCalls === 0 && $readyCalls === 0, 'Boot must not evaluate identity or account readiness.');
$assert(!class_exists(\TinyCat\Database\Connection::class, false), 'Boot does not connect to the database.');
$assert(\TinyCat\Database\Schema::app_required_tables() === [], 'The minimal app has no business tables.');
$assert(\TinyCat\Core\Modules\Registrations::current()->entries->tasks === [], 'The minimal app has no domain tasks.');
$assert(\TinyCat\Support\Localization::locale() === 'cs', 'Locale uses the supplied configuration.');
$assert((new ReflectionMethod(\TinyCat\Support\Cache::class, 'driver'))->invoke(null) === 'filesystem', 'Cache uses supplied technical configuration.');
$assert(\TinyCat\Database\Schema::app_db_ready() && $readyCalls === 1, 'Readiness uses the supplied callback and in-memory DSN.');
$assert((int) \TinyCat\Database\Connection::value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'") === 0, 'The configured database contains no social or account tables.');
\TinyCat\Http\Router::route('GET', '/_kernel-probe', static function (): void { echo 'kernel'; });
ob_start();
$matched = \TinyCat\Http\Router::dispatch('/_kernel-probe', 'GET');
$assert($matched && ob_get_clean() === 'kernel', 'The minimal app dispatches a technical route.');
$assert($identityCalls === 0, 'Public routing does not resolve an identity.');
$resolver = (new ReflectionProperty($api, 'currentUser'))->getValue($api);
$assert($resolver()['id'] === 7 && $identityCalls === 1, 'The API receives the supplied identity resolver.');
foreach (['TinyCat\\Social\\Profiles\\Registration', 'TinyCat\\Core\\Identity\\Authentication', 'TinyCat\\Social\\Posts\\Registration', 'TinyCat\\Social\\Automation\\Registration', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Http\\Session'] as $class) {
    $assert(!class_exists($class, false), 'Minimal runtime did not load ' . $class);
}
$configFile = str_replace('\\', '/', dirname(__DIR__, 3) . '/config.php');
$assert(!in_array($configFile, array_map(static fn (string $file): string => str_replace('\\', '/', $file), get_included_files()), true), 'Minimal runtime did not read the local configuration file.');
$revision = \TinyCat\Core\Modules\Resources::revision();
$policyCalls = 0;
\TinyCat\Http\Middleware::register('fixture.access', static function () use (&$policyCalls): void { $policyCalls++; });
$api->get('/_custom-policy', static fn (): array => [], 'fixture.access');
$assert($policyCalls === 0, 'Registering a custom access policy does not authorize eagerly.');
\TinyCat\Http\Middleware::authorize('fixture.access', false);
$assert($policyCalls === 1, 'Custom authorization is supplied without a core domain switch.');
try { $api->get('/_unknown-policy', static fn (): array => [], 'fixture.unknown'); throw new RuntimeException('Unknown access policy accepted.'); }
catch (LogicException) { $assert(true, 'Unknown access policy is rejected.'); }
$renders = 0;
\TinyCat\Core\Ui\Page::provide('fixture.one', static function (array $page, string $content) use (&$renders): void { $renders++; echo $content; });
try { \TinyCat\Core\Ui\Page::provide('fixture.two', static function (array $page, string $content): void {}); throw new RuntimeException('Conflicting presentation accepted.'); }
catch (LogicException) { $assert($renders === 0, 'Presentation registration stays lazy and rejects conflicting owners.'); }
ob_start(); \TinyCat\Core\Ui\Page::render([], 'surface');
$assert(ob_get_clean() === 'surface' && $renders === 1, 'The shared page uses its selected presentation provider.');
try { \TinyCat\Core\Runtime\Application::boot(new \TinyCat\Core\Runtime\Context()); throw new RuntimeException('Duplicate boot was accepted.'); }
catch (LogicException) { $assert(\TinyCat\Core\Modules\Resources::revision() === $revision, 'Duplicate boot fails before changing shared state.'); }

// Platform services remain available when no functional module is selected.
foreach ([\TinyCat\Core\Tasks\Runner::class, \TinyCat\Core\Tasks\History::class, \TinyCat\Core\Tasks\Cleanup::class, \TinyCat\Core\Mail\Smtp::class, \TinyCat\Core\Settings\Store::class] as $class) {
    $assert(class_exists($class), 'Platform implementation is available without a module: ' . $class);
}
$assert(\TinyCat\Core\Tasks\HealthCheck::run()['metrics'] === [], 'Task diagnostics do not invent business tasks.');
$assert(\TinyCat\Core\Tasks\FileCleanup::temporary(1) === ['changed' => 0, 'has_more' => false], 'An empty catalog supplies no temporary directories.');
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
