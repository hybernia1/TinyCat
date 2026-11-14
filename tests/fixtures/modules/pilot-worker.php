<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/autoload.php';
$directory = (string) getenv('TINYCAT_PILOT_DIRECTORY');
$database = json_decode((string) file_get_contents($directory . '/database.json'), true, 512, JSON_THROW_ON_ERROR);
$selection = require $root . '/App/modules.php';
if (is_file($directory . '/enabled')) { $selection['demo.notes'] = str_replace('\\', '/', substr($directory, strlen($root) + 1)) . '/Notes/module.json'; }
$modules = \TinyCat\Core\Modules\Loader::fromSelection($root, $selection);
$context = new \TinyCat\Core\Runtime\Context(new \TinyCat\Core\Runtime\Configuration(['database' => $database, 'i18n' => ['locale' => 'en']]),
    identity: static fn (): ?array => ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer pilot-admin' ? ['id' => 7, 'role' => 'admin'] : null);
\TinyCat\Core\Runtime\Application::boot($context, $modules);
$mode = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : '';
$pdo = \TinyCat\Database\Connection::get();
if ($mode === 'install') {
    \TinyCat\Database\ModuleSchemas::install($pdo, $modules->schemas());
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec('CREATE TABLE IF NOT EXISTS scheduler_runs (id ' . $id . ', task VARCHAR(100), mode VARCHAR(16), actor_id INTEGER NULL, status VARCHAR(24), started_at DATETIME, finished_at DATETIME NULL, duration_ms INTEGER NULL, options_json TEXT, summary_json TEXT NULL, error TEXT NULL)');
    echo "installed\n";
    return;
}
if ($mode === 'task') {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->sqliteCreateFunction('GET_LOCK', static fn (): int => 1);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn (): int => 1);
    }
    $result = \TinyCat\Core\Tasks\Execution::run('pilot.notes.refresh', ['batch' => 2]);
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit($result['ok'] ? 0 : 1);
}
if ($mode === 'state') {
    echo json_encode([
        'active' => $modules->catalog->has('demo.notes'),
        'task' => isset(\TinyCat\Core\Tasks\Tasks::definitions()['pilot.notes.refresh']),
        'autoloaded' => class_exists('TinyCat\\Modules\\PilotNotes\\Notes'),
        'rows' => (int) $pdo->query('SELECT COUNT(*) FROM pilot_notes')->fetchColumn(),
        'version' => \TinyCat\Database\ModuleSchemas::state($pdo, 'demo.notes'),
    ], JSON_THROW_ON_ERROR);
    return;
}

// Only this isolated test entry point exposes fixture authentication/CSRF setup.
$path = \TinyCat\Http\Request::current()->path();
if ($path === '/_pilot/csrf') { \TinyCat\Http\Response::success(['token' => \TinyCat\Core\Security\Csrf::token()]); }
if ($path === '/api/_pilot/gate') {
    $gated = (new \TinyCat\Http\Api(ready: static fn (): bool => false))->whenReady(static fn (): bool => true);
    $gated->get('/_pilot/gate', static fn (): array => ['must_not_run' => true]);
    \TinyCat\Http\Router::dispatch($path);
}
if (str_starts_with($path, '/cache/assets/')) { if (\TinyCat\Http\Router::dispatch($path)) { return; } }
if (!in_array($path, ['/api/pilot/notes', '/pilot/notes'], true)) { http_response_code(404); return; }
if (!\TinyCat\Http\Router::dispatch($path)) { \TinyCat\Http\Response::error('Not found.', 404, 'not_found'); }
