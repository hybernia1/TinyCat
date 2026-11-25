<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

// Only the owning regression supplies this isolated application root.
$root = $argv[1];
$action = $argv[2];
define('TINYCAT', true);
require $root . '/App/Core/Runtime/autoload.php';
use TinyCat\Core\Modules\Contributions;
use TinyCat\Core\Modules\Registrations;
use TinyCat\Core\Modules\Registries;
use TinyCat\Core\Modules\ScheduledTask;
use TinyCat\Core\Tasks\Execution;
use TinyCat\Core\Tasks\Runner;
use TinyCat\Core\Tasks\Tasks;
use TinyCat\Core\Updates\Manager;

TinyCat\Support\Config::load();
if ($action === 'console-pending') { TinyCat\Core\Runtime\Debug::configure('on'); exit(TinyCat\Core\Runtime\Console::run(['App/Console.php', 'status'])); }
if ($action === 'entrypoint') {
    try { TinyCat\Core\Runtime\Entrypoints::run('scheduled-tasks.php'); }
    catch (Throwable $error) { echo json_encode(['rejected' => $error->getMessage()], JSON_THROW_ON_ERROR); }
    exit;
}
if ($action === 'prepare') {
    $plan = TinyCat\Core\Modules\Distribution::describe($root, 'tinycat.fixture', []);
    file_put_contents($root . '/App/distribution.json', json_encode($plan, JSON_THROW_ON_ERROR));
    exit;
}
if ($action === 'scheduled') {
    $argv = ['scheduled-tasks.php', '--task=cleanup'];
    $_SERVER['argv'] = $argv;
    require $root . '/App/Core/Tasks/entrypoints/scheduled-tasks.php';
    exit;
}
if ($action === 'console') { exit(TinyCat\Core\Runtime\Console::run(['console', 'task', 'cleanup'])); }
if ($action === 'finish-console') { exit(TinyCat\Core\Runtime\Console::run(['console', 'finish-deployment', $argv[3] ?? ''])); }

$database = \tinycat_test_database($root . '/fixture.database.json');
TinyCat\Database\Connection::set($database);
$loader = TinyCat\Core\Modules\Loader::fromSelection($root, []);
TinyCat\Core\Modules\Resources::configure($loader->catalog);
$callback = static function () use ($root): array {
    file_put_contents($root . '/called', 'yes', FILE_APPEND);
    return Tasks::result('completed');
};
Registrations::configure(new Registries(['fixture' => new Contributions(tasks: [
    new ScheduledTask('cleanup', $callback), new ScheduledTask('site-health', $callback), new ScheduledTask('second', $callback),
])]));
TinyCat\Core\Identity\Authentication::reset(['id' => 1, 'role' => 'admin'], true);
try {
    $result = match ($action) {
        'runner' => Runner::run('cleanup', $callback, []),
        'prepared' => Runner::runPrepared('cleanup', $callback, Tasks::context('cleanup', [])),
        'execution' => Execution::run('all', [], $argv[3] ?? 'http'),
        'cleanup' => TinyCat\Core\Updates\Cleanup::run(20),
        'update' => Manager::runLifecycle(static fn (): array => [], false),
        'snapshot' => Manager::runLifecycle(static fn (): array => [], true),
        'rollback' => Manager::rollback(($argv[3] ?? '') !== '' ? $argv[3] : 'fixture-backup'),
        'finish-commit' => (static function () use ($argv): array { Manager::finishCommittedOperation($argv[3] ?? ''); return ['finished' => true]; })(),
        'fail-update' => Manager::runLifecycle(static function (): array { throw new RuntimeException('Interrupted fixture deployment.'); }, false),
        'hold' => Runner::run('cleanup', static function () use ($root): array {
            file_put_contents($root . '/ready', 'ready');
            $deadline = microtime(true) + 15;
            while (!is_file($root . '/release')) {
                if (microtime(true) > $deadline) { throw new RuntimeException('Hold deadline exceeded.'); }
                usleep(10000);
            }
            return Tasks::result('completed');
        }, []),
        'second' => Execution::run('second'),
        'nested' => Runner::run('cleanup', static function (): array {
            $nested = Execution::run('second');
            $cleanup = TinyCat\Core\Updates\Cleanup::run(20);
            return Tasks::result('completed', details: ['nested' => $nested, 'cleanup' => $cleanup]);
        }, []),
        'throw' => Runner::run('cleanup', static function (): array { throw new RuntimeException('Fixture failure.'); }, []),
        default => null,
    };
    if ($result !== null) { echo json_encode($result, JSON_THROW_ON_ERROR); exit; }
    // Exercise the real registered API routes and response envelope in a child request.
    // Authentication policy is fixture supplied; authorization is covered by api-contract.
    TinyCat\Http\Middleware::register('scheduler', static function (): void {});
    TinyCat\Http\Middleware::register('scheduler_trigger', static function (): void {});
    session_save_path($root . '/sessions');
    TinyCat\Http\Session::start();
    $_SESSION['_csrf'] = str_repeat('a', 64);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['_csrf'];
    $_SERVER['REQUEST_METHOD'] = $action === 'trigger' || $action === 'probe' ? 'GET' : 'POST';
    $_SERVER['REQUEST_URI'] = match ($action) {
        'trigger' => '/api/scheduler/triggers/cleanup', 'probe' => '/api/scheduler/health',
        'api' => '/api/scheduler/executions', 'admin' => '/api/scheduler/tasks/cleanup/runs',
        'health' => '/api/scheduler/tasks/site-health/runs',
    };
    $_POST = ['task' => 'cleanup'];
    $api = new TinyCat\Http\Api(static fn (): array => ['id' => 1, 'role' => 'admin']);
    TinyCat\Core\Tasks\Api::register($api);
    TinyCat\Http\Router::dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
} catch (Throwable $error) {
    echo json_encode(['rejected' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
