<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/autoload.php';
use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\ModuleSchema;
use TinyCat\Database\ModuleSchemas;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$reject = static function (callable $action, string $message) use ($assert): void {
    try { $action(); } catch (RuntimeException|InvalidArgumentException $error) { $assert($error->getMessage() !== '', $message); return; }
    throw new RuntimeException($message . ' was accepted');
};
$directory = $root . '/storage/module-pilot-' . bin2hex(random_bytes(6));
$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_file($from)) { if (!copy($from, $to)) { throw new RuntimeException('Fixture copy failed.'); } return; }
    mkdir($to, 0700, true);
    foreach (scandir($from) ?: [] as $name) { if ($name !== '.' && $name !== '..') { $copy($from . '/' . $name, $to . '/' . $name); } }
};
$remove = static function (string $path) use (&$remove, $directory): void {
    $base = realpath($directory); $resolved = realpath($path);
    if ($base === false || $resolved === false || ($resolved !== $base && !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR))) { throw new RuntimeException('Invalid fixture cleanup target.'); }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $child = $path . '/' . $name;
        if (is_link($child) || is_file($child)) { unlink($child); } else { $remove($child); }
    }
    rmdir($path);
};
mkdir($directory, 0700);
$server = null; $mysql = null; $mysqlNames = [];
try {
    $copy($root . '/tests/fixtures/modules/notes', $directory . '/Notes');
    $relative = str_replace('\\', '/', substr($directory, strlen($root) + 1)) . '/Notes';
    $assets = json_decode((string) file_get_contents($directory . '/Notes/assets/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $assets[0]['entries'] = [$relative . '/assets/index.js'];
    $assets[0]['styles'] = [$relative . '/assets/styles.css'];
    file_put_contents($directory . '/Notes/assets/module.json', json_encode($assets, JSON_THROW_ON_ERROR));
    foreach (\TinyCat\Support\TranslationSources::packages() as $locale => $_) {
        if (!is_file($directory . '/Notes/lang/' . $locale . '.json')) { copy($directory . '/Notes/lang/en.json', $directory . '/Notes/lang/' . $locale . '.json'); }
    }
    $selection = require $root . '/App/modules.php';
    $selection['demo.notes'] = $relative . '/module.json';
    $modules = Loader::fromSelection($root, $selection);
    $schemas = $modules->schemas();
    $registries = $modules->registries();
    $tables = $modules->register(new \TinyCat\Http\Api());
    $assert(!class_exists(\TinyCat\Database\Connection::class, false), 'Manifest providers and routes are discovered without connecting to a database');
    $assert(!class_exists(\TinyCat\Core\Settings\Store::class, false), 'Manifest discovery does not load application settings');
    $assert(in_array('pilot_notes', $tables, true) && in_array('module_migrations', $tables, true), 'Selected schema tables join the readiness inventory');
    $assert($registries->owner('task', 'pilot.notes.refresh') === 'demo.notes', 'Manifest contribution provider is registered');
    $missingDependency = ['demo.notes' => $relative . '/module.json'];
    $assert(Loader::fromSelection($root, $missingDependency)->catalog->has('tinycat.scheduler'), 'Module receives the mandatory scheduler capability without selecting a framework module');
    $reject(static fn () => \TinyCat\Core\Modules\Providers::assertDistributable($modules->catalog), 'Publishing schemas without a module-aware update path');

    $invalid = static function (string $name, string $namespace, array $providers, string $source = '') use ($directory, $root, $selection): Loader {
        $base = $directory . '/' . $name;
        mkdir($base . '/src', 0700, true); mkdir($base . '/database', 0700);
        copy($directory . '/Notes/database/migrations/0001.php', $base . '/database/0001.php');
        if ($source !== '') { file_put_contents($base . '/src/Provider.php', $source); }
        file_put_contents($base . '/module.json', json_encode([
            'format' => 1, 'coreApi' => 1, 'id' => 'fixture.' . strtolower($name), 'namespace' => $namespace,
            'providers' => (object) $providers, 'resources' => ['migrations' => 'database'],
        ], JSON_THROW_ON_ERROR));
        $map = $selection;
        $map['fixture.' . strtolower($name)] = str_replace('\\', '/', substr($base, strlen($root) + 1)) . '/module.json';
        return Loader::fromSelection($root, $map);
    };
    $unsupported = $invalid('Unsupported', 'Fixture\\Unsupported\\', ['permissions' => 'Fixture\\Unsupported\\Provider']);
    $reject(static fn () => $unsupported->registries(), 'An unsupported provider kind');
    $badStartup = $invalid('BadStartup', 'Fixture\\BadStartup\\', ['services' => 'Fixture\\BadStartup\\Provider'], '<?php namespace Fixture\\BadStartup; final class Provider {}');
    $optionsBefore = TinyCat\Core\Runtime\Startup::$options;
    $reject(static fn () => $badStartup->initialize(), 'Initializer without its required interface');
    $assert(TinyCat\Core\Runtime\Startup::$options === $optionsBefore, 'Validate every initializer before registering runtime policies');
    $wrong = $invalid('Wrong', 'Fixture\\Wrong\\', ['contributions' => 'Fixture\\Wrong\\Provider'], '<?php declare(strict_types=1); namespace Fixture\\Wrong; final class Provider {}');
    $reject(static fn () => $wrong->registries(), 'Provider without its required interface');
    $collisionSource = <<<'PHP'
<?php
declare(strict_types=1);
namespace Fixture\Collision;
final class Provider implements \TinyCat\Core\Modules\ProvidesSchema {
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema {
        return new \TinyCat\Core\Modules\ModuleSchema(1, ['users'], static function (\PDO $pdo): void { throw new \RuntimeException('DDL must not execute'); }, [new \TinyCat\Core\Modules\SchemaMigration(1, 'fixture.collision.0001', dirname(__DIR__) . '/database/0001.php')]);
    }
}
PHP;
    $collision = $invalid('Collision', 'Fixture\\Collision\\', ['schema' => 'Fixture\\Collision\\Provider'], $collisionSource);
    $routeSnapshot = (new ReflectionProperty(\TinyCat\Http\Router::class, 'routes'))->getValue();
    $reject(static fn () => $collision->register(new \TinyCat\Http\Api()), 'A schema claiming a legacy table');
    $assert((new ReflectionProperty(\TinyCat\Http\Router::class, 'routes'))->getValue() === $routeSnapshot, 'Schema collisions fail before registering any routes');

    $compile = require $root . '/tools/assets/compile-assets.php';
    $baseline = $compile();
    Resources::withCatalog($modules->catalog, static function () use ($assert, $compile): void {
        $files = $compile();
        $manifest = json_decode($files['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $scope = $manifest['clients']['/pilot/notes'];
        $assert(str_contains($files[ltrim($scope['app']['raw'], '/')], 'pilotNotes'), 'Co-located module JavaScript is bundled');
        $assert(str_contains($files[ltrim($scope['styles']['raw'], '/')], '.pilot-notes-list'), 'Co-located module CSS is bundled');
        $assert(isset(\TinyCat\Support\ClientAssets::definitions('/pilot/notes')['pilot-notes/list']), 'Module components join the common renderer');
        $assert(str_contains($files[ltrim($scope['catalogs']['cs'], '/')], 'pilot_notes.title'), 'Module client translations are compiled');
        $assert(\TinyCat\Support\TranslationSources::catalog('cs')['pilot_notes.title'] === 'Pilotní poznámky', 'Module server translations are selected');
    });
    $assert($compile() === $baseline, 'The default build remains byte-identical after leaving the pilot selection');
    $source = (string) file_get_contents($directory . '/Notes/assets/index.js');
    file_put_contents($directory . '/Notes/assets/index.js', 'const forbidden = \'<div class="modal">\';');
    $reject(static fn () => Resources::withCatalog($modules->catalog, $compile), 'A co-located asset bypassing modal structure checks');
    file_put_contents($directory . '/Notes/assets/index.js', $source);
    \TinyCat\Http\Router::route('GET', '/_pilot-collision', static fn (): null => null);
    $before = (new ReflectionProperty(\TinyCat\Http\Router::class, 'routes'))->getValue();
    $reject(static fn () => \TinyCat\Http\Router::registerModule(static function (): void {
        \TinyCat\Http\Router::route('GET', '/_pilot-new', static fn (): null => null);
        \TinyCat\Http\Router::route('HEAD', '/_pilot-collision', static fn (): null => null);
    }), 'A module route shadowing an existing GET/HEAD route');
    $assert((new ReflectionProperty(\TinyCat\Http\Router::class, 'routes'))->getValue() === $before, 'Rejected route providers leave no partial routes');

    $schema = $schemas['demo.notes'];
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $sqliteConfig = ['dsn' => 'sqlite:' . $directory . '/http.sqlite'];
    $pdo = new PDO($sqliteConfig['dsn'], null, null, $options);
    $old = new PDO('sqlite::memory:', null, null, $options);
    $structure = static function (PDO $connection): array {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return ['columns' => $connection->query('SHOW COLUMNS FROM pilot_notes')->fetchAll(PDO::FETCH_ASSOC),
                'indexes' => array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['Key_name', 'Non_unique', 'Seq_in_index', 'Column_name'])), $connection->query('SHOW INDEX FROM pilot_notes')->fetchAll(PDO::FETCH_ASSOC))];
        }
        return ['columns' => $connection->query('PRAGMA table_info(pilot_notes)')->fetchAll(PDO::FETCH_ASSOC), 'indexes' => $connection->query('PRAGMA index_list(pilot_notes)')->fetchAll(PDO::FETCH_ASSOC), 'foreign_keys' => $connection->query('PRAGMA foreign_key_list(pilot_notes)')->fetchAll(PDO::FETCH_ASSOC)];
    };
    $matrix = static function (PDO $fresh, PDO $upgrade) use ($schema, $assert, $reject, $structure): void {
        ModuleSchemas::install($fresh, ['demo.notes' => $schema]);
        ModuleSchemas::install($fresh, ['demo.notes' => $schema]);
        $assert(ModuleSchemas::ready($fresh, ['demo.notes' => $schema]), 'Fresh install is ready and repeat-safe');
        $initial = new ModuleSchema(1, $schema->tables, require $schema->migrations[0]->file, [$schema->migrations[0]]);
        ModuleSchemas::install($upgrade, ['demo.notes' => $initial]);
        $upgrade->exec("INSERT INTO pilot_notes (title, created_by) VALUES ('preserved', 7)");
        ModuleSchemas::upgrade($upgrade, ['demo.notes' => $schema]);
        $assert($structure($fresh) === $structure($upgrade), 'Fresh and upgraded schema columns/indexes match');
        $row = $upgrade->query('SELECT title, created_by, refresh_count FROM pilot_notes')->fetch(PDO::FETCH_ASSOC);
        $assert($row['title'] === 'preserved' && (int) $row['created_by'] === 7 && (int) $row['refresh_count'] === 0, 'Upgrade preserves existing records');
        ModuleSchemas::upgrade($upgrade, ['demo.notes' => $schema]);
        $assert((int) $upgrade->query('SELECT COUNT(*) FROM pilot_notes')->fetchColumn() === 1, 'Repeated upgrade does not replay data changes');
        $upgrade->exec("UPDATE module_migrations SET checksum = '" . str_repeat('0', 64) . "' WHERE version = 1");
        $reject(static fn () => ModuleSchemas::upgrade($upgrade, ['demo.notes' => $schema]), 'Changed applied migration checksum');
        $assert(ModuleSchemas::state($upgrade, 'demo.notes')['status'] === 'ready', 'Checksum rejection happens before changing installation state');
        $restore = $upgrade->prepare('UPDATE module_migrations SET checksum = ? WHERE version = 1'); $restore->execute([ModuleSchemas::checksum($schema->migrations[0]->file)]);
        $reject(static fn () => ModuleSchemas::upgrade($upgrade, ['demo.notes' => $initial]), 'Schema downgrade');
        $reject(static fn () => ModuleSchemas::install($fresh, ['demo.one' => $schema, 'demo.two' => $schema]), 'Duplicate schema table owners');
    };
    $matrix($pdo, $old);
    $failure = new PDO('sqlite::memory:', null, null, $options);
    $initial = new ModuleSchema(1, $schema->tables, require $schema->migrations[0]->file, [$schema->migrations[0]]);
    ModuleSchemas::install($failure, ['demo.notes' => $initial]);
    $beforeFailure = $structure($failure);
    $failureFile = $directory . '/Notes/database/migrations/failure.php';
    file_put_contents($failureFile, '<?php declare(strict_types=1); return static function (PDO $pdo): void { $pdo->exec("ALTER TABLE pilot_notes ADD COLUMN aborted INTEGER"); throw new RuntimeException("injected migration failure"); };');
    $badSchema = new ModuleSchema(2, $schema->tables, $schema->install, [$schema->migrations[0], new \TinyCat\Core\Modules\SchemaMigration(2, 'demo.notes.failure', $failureFile)]);
    $reject(static fn () => ModuleSchemas::upgrade($failure, ['demo.notes' => $badSchema]), 'Failed SQLite module migration');
    $assert($structure($failure) === $beforeFailure && (int) ModuleSchemas::state($failure, 'demo.notes')['version'] === 1, 'SQLite rolls back both DDL and migration state on failure');
    $failure = null;
    unlink($failureFile);
    $old = null;

    file_put_contents($directory . '/database.json', json_encode($sqliteConfig, JSON_THROW_ON_ERROR));
    chmod($directory . '/database.json', 0600);
    file_put_contents($directory . '/enabled', '1');
    mkdir($directory . '/sessions', 0700);
    $environment = array_merge(getenv(), ['TINYCAT_PILOT_DIRECTORY' => $directory]);
    $worker = $root . '/tests/fixtures/modules/pilot-worker.php';
    $run = static function (string $mode) use ($environment, $worker, $root): string {
        $process = proc_open([PHP_BINARY, $worker, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start pilot worker.'); }
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Pilot worker failed: ' . $output . $errors); }
        return $output;
    };
    $run('install');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) { throw new RuntimeException('Cannot allocate pilot server port.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $directory . '/sessions', '-S', $address, $worker], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, $root, $environment);
    if (!is_resource($server)) { throw new RuntimeException('Cannot start pilot HTTP server.'); }
    fclose($pipes[0]);
    $request = static function (string $path, ?array $payload = null, bool $admin = false, string $csrf = '') use ($address, $directory): array {
        $handle = curl_init('http://' . $address . $path);
        $headers = ['Accept: application/json'];
        if ($admin) { $headers[] = 'Authorization: Bearer pilot-admin'; }
        if ($csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
        if ($payload !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR)); }
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEJAR => $directory . '/cookies', CURLOPT_COOKIEFILE => $directory . '/cookies', CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'json' => is_string($body) ? json_decode($body, true) : null];
    };
    for ($attempt = 0; $attempt < 50; $attempt++) { if ($request('/_pilot/csrf')['status'] === 200) { break; } usleep(100000); }
    file_put_contents($directory . '/database.json', json_encode(['dsn' => 'sqlite:' . $directory . '/uninstalled.sqlite'], JSON_THROW_ON_ERROR));
    $notReady = $request('/api/pilot/notes');
    $assert($notReady['status'] === 503 && $notReady['json']['code'] === 'module_not_ready', 'Uninstalled module API fails before its handler runs');
    $empty = new PDO('sqlite:' . $directory . '/uninstalled.sqlite', null, null, $options);
    $assert((int) $empty->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn() === 0, 'HTTP readiness checks never install schema automatically');
    $empty = null;
    file_put_contents($directory . '/database.json', json_encode($sqliteConfig, JSON_THROW_ON_ERROR));
    $assert($request('/api/pilot/notes')['status'] === 200, 'Selected manifest API is reachable');
    $assert($request('/api/_pilot/gate')['status'] === 503, 'A nested module gate cannot override a failed parent readiness policy');
    $assert($request('/api/pilot/notes', ['title' => 'forbidden'])['status'] === 401, 'API write requires an authenticated actor');
    $assert($request('/api/pilot/notes', ['title' => 'forbidden'], true)['status'] === 403, 'API write retains shared CSRF protection');
    $token = $request('/_pilot/csrf')['json']['data']['token'];
    $assert($request('/api/pilot/notes', ['title' => ''], true, $token)['status'] === 422, 'Module validation uses the common JSON errors');
    foreach (['one', 'two', '<script>alert(1)</script>'] as $title) { $assert($request('/api/pilot/notes', ['title' => $title, 'created_by' => 999], true, $token)['status'] === 201, 'Authorized note creation'); }
    $items = $request('/api/pilot/notes')['json']['data']['items'];
    $assert(count($items) === 3 && $items[0]['created_by'] === 7, 'The actor comes from authorization, not request payload');
    $page = $request('/pilot/notes');
    $assert($page['status'] === 200 && str_contains($page['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($page['body'], '<script>alert(1)</script>'), 'Module templates use the safe shared component renderer');
    $assert(substr_count($page['body'], '<script src=') === 4 && substr_count($page['body'], 'rel="stylesheet"') === 1, 'The pilot keeps shared script/style delivery budgets');
    $task = json_decode($run('task'), true, 512, JSON_THROW_ON_ERROR);
    $assert($task['ok'] && $task['tasks']['pilot.notes.refresh']['changed'] === 2, 'A manifest task runs through scheduler locks and history');
    $assert((int) $pdo->query('SELECT SUM(refresh_count) FROM pilot_notes')->fetchColumn() === 2, 'The task respects its registered batch');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM scheduler_runs WHERE task = 'pilot.notes.refresh' AND status = 'completed'")->fetchColumn() === 1, 'Task history preserves its stable module task ID');
    unlink($directory . '/enabled');
    $assert($request('/api/pilot/notes')['status'] === 404, 'Removing selection removes the API on the next request');
    $inactive = json_decode($run('state'), true, 512, JSON_THROW_ON_ERROR);
    $assert(!$inactive['active'] && !$inactive['task'] && !$inactive['autoloaded'] && $inactive['rows'] === 3 && (int) $inactive['version']['version'] === 2, 'Deactivation removes code/tasks but preserves data and schema history');
    proc_terminate($server); proc_close($server); $server = null;
    $pdo = null;

    require $root . '/tests/fixtures/modules/fresh-baseline.php';

    if (is_file($root . '/config.php')) {
        $base = require $root . '/config.php';
        $config = $base['database'] ?? [];
        if (isset($config['host'], $config['user'])) {
            $dsn = 'mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306) . ';charset=utf8mb4';
            $mysql = new PDO($dsn, $config['user'], $config['password'] ?? '', $options);
            foreach (['fresh', 'upgrade'] as $kind) {
                $name = 'tinycat_pilot_' . $kind . '_' . bin2hex(random_bytes(5));
                $mysql->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4'); $mysqlNames[$kind] = $name;
            }
            $fresh = new PDO($dsn . ';dbname=' . $mysqlNames['fresh'], $config['user'], $config['password'] ?? '', $options);
            $upgrade = new PDO($dsn . ';dbname=' . $mysqlNames['upgrade'], $config['user'], $config['password'] ?? '', $options);
            $matrix($fresh, $upgrade);
            $fresh = null; $upgrade = null;
        } else { echo "SKIP module pilot MySQL matrix: no local MySQL configuration\n"; }
    } else { echo "SKIP module pilot MySQL matrix: no local configuration file\n"; }
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($mysql instanceof PDO) { foreach ($mysqlNames as $name) { if (!preg_match('/^tinycat_pilot_(?:fresh|upgrade)_[a-f0-9]+$/D', $name)) { throw new RuntimeException('Invalid disposable database name.'); } $mysql->exec('DROP DATABASE `' . $name . '`'); } }
    $remove($directory);
}
echo 'PASS manifest module pilot, schema parity, HTTP authorization, assets and lifecycle (' . $checks . " checks)\n";
