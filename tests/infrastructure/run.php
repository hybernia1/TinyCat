<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/cache-isolation.php';

use \TinyCat\Database\Connection;
use \TinyCat\Database\Query;
use \TinyCat\Database\Transactions;
use \TinyCat\Http\Request;
use \TinyCat\Http\Router;
use \TinyCat\Core\Security\Csrf;
use \TinyCat\Http\Session;

define('TINYCAT', true);
require_once __DIR__ . '/../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$throws = static function (callable $operation, string $class) use ($assert): void {
    try { $operation(); } catch (Throwable $exception) {
        $assert($exception instanceof $class, 'Unexpected exception: ' . $exception::class);
        return;
    }
    throw new RuntimeException('Expected exception: ' . $class);
};

$validateResource = new ReflectionMethod(\TinyCat\Http\Response::class, 'assertData');
$resource = ['items' => [['id' => 1, 'body' => '<b>plain data</b>', 'active' => true, 'value' => null]], 'labels' => [], 'formatted_at' => 'now'];
$beforeResource = json_encode($resource, JSON_THROW_ON_ERROR);
$validateResource->invoke(null, $resource);
$assert(json_encode($resource, JSON_THROW_ON_ERROR) === $beforeResource, 'Resource validation preserves canonical data and literal text.');
foreach (['html', 'body_HTML', 'Label', 'value_formatted', 'ui', 'components', 'translations', 'role_options', 'status_options', 'username_hint'] as $key) {
    $throws(static fn () => $validateResource->invoke(null, ['nested' => [[$key => null]]]), LogicException::class);
}
$throws(static fn () => $validateResource->invoke(null, ['items' => [['body' => new stdClass()]]]), LogicException::class);
$resourceStream = fopen('php://memory', 'w+');
try { $throws(static fn () => $validateResource->invoke(null, ['items' => [$resourceStream]]), LogicException::class); }
finally { fclose($resourceStream); }
$manyFields = [];
for ($index = 0; $index < 300; $index++) { $manyFields['field_' . $index] = $index; }
$validateResource->invoke(null, $manyFields);
$assert(count((new ReflectionProperty(\TinyCat\Http\Response::class, 'validatedKeys'))->getValue()) <= 256, 'Arbitrary resource field names cannot grow the validation cache without bound.');
$throws(static fn () => $validateResource->invoke(null, ['nested' => ['body_html' => '']]), LogicException::class);
$throws(static fn () => $validateResource->invoke(null, ['id' => new stdClass()]), LogicException::class);

foreach ([
    ['benchmark', '-', 'benchmark'], ['mobile-first', '-', 'mobile-first'],
    ['123', '-', '123'], ['  cache-hit  ', '-', 'cache-hit'],
    ['Cache HIT', '-', 'cache-hit'], ['a--b', '-', 'a-b'],
    ['-a-', '-', 'a'], ['a_b', '-', 'a-b'], ['', '-', ''],
    ['a-b', '_', 'a_b'], ['a b', '', 'a-b'],
    ['Příliš žluťoučký kůň', '-', 'prilis-zlutoucky-kun'],
    ['Žluťoučký', '_', 'zlutoucky'],
] as [$input, $separator, $expected]) {
    $assert(\TinyCat\Support\Text::slug($input, $separator) === $expected, 'Slug normalization preserves canonical tags, separators and Unicode transliteration.');
}
$request = new Request(
    serverData: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/items/7?view=html', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    queryData: ['value' => 'query', 'nested' => ['q' => 1]],
    postData: [],
    rawBody: '{"_method":"PATCH","value":"body","nested":{"body":2}}'
);
$assert($request->method() === 'PATCH', 'JSON method override is preserved.');
$assert($request->input('value') === 'body' && $request->input('nested.q') === 1 && $request->input('nested.body') === 2, 'Body precedence and nested query values are preserved.');
$assert($request->path() === '/api/items/7' && $request->header('Content-Type') === 'application/json', 'Path and headers have a request owner.');
$assert($request->wantsJson(), 'Request accepts JSON.');
$throws(static fn () => (new Request(serverData: ['CONTENT_TYPE' => 'application/json'], postData: [], rawBody: '{broken'))->input(), InvalidArgumentException::class);
$throws(static fn () => (new Request(serverData: ['REQUEST_METHOD' => 'POST'], postData: ['_method' => []]))->method(), InvalidArgumentException::class);
$form = new Request(serverData: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], postData: [], rawBody: 'name=Alice&nested%5Bid%5D=7');
$assert($form->input('nested.id') === '7', 'Form-encoded body parsing is preserved.');
$queryOnly = new Request(serverData: ['REQUEST_METHOD' => 'POST'], queryData: ['_method' => 'DELETE'], postData: [], rawBody: '');
$assert($queryOnly->method() === 'POST', 'A query parameter cannot override the HTTP method.');
$validated = (new Request(postData: ['name' => 'Alice', 'unexpected' => 'discard']))->validated(['name' => 'required|string|max:32']);
$assert($validated === ['name' => 'Alice'], 'Request validation only returns explicitly allowed fields.');
$assert(isset(\TinyCat\Http\Validator::validate(['name' => []], ['name' => 'required|string'])['name']), 'Shared validation rejects invalid types without a domain dependency.');
set_error_handler(static function (int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
try {
    foreach ([[['published'], 'in:published,hidden'], [new stdClass(), 'in:published,hidden'], [true, 'integer'], [1.0, 'integer'], [INF, 'numeric'], ['1e999', 'numeric']] as [$value, $rule]) {
        $errors = \TinyCat\Http\Validator::validate(['value' => $value], ['value' => $rule], structured: true);
        $assert(isset($errors['value']) && is_string($errors['value'][0]['code']), 'Invalid input types produce structured field errors without PHP diagnostics: ' . $rule);
    }
    foreach ([[false, 'required|boolean'], [0, 'required|integer'], ['42', 'integer|min:1|max:100'], [[1, 2], 'array|max:2'], [null, 'nullable|string']] as [$value, $rule]) {
        $assert(\TinyCat\Http\Validator::validate(['value' => $value], ['value' => $rule]) === [], 'Validation preserves supported JSON/form values: ' . $rule);
    }
    foreach (['unknown_rule', 'max:nope', 'between:9,1', 'required:extra', 'in:', ['string', []]] as $rules) {
        $throws(static fn () => \TinyCat\Http\Validator::validate([], ['absent' => $rules]), LogicException::class);
    }
    $throws(static fn () => \TinyCat\Http\Validator::validate(['value' => ''], ['value' => 'required'], ['required' => []], true), LogicException::class);
} finally { restore_error_handler(); }
$assert((new Request(postData: ['id' => '42']))->validated(['id' => 'required|integer']) === ['id' => '42'], 'Validation checks types without silently coercing form input.');
$definition = ['title' => 'required|string|max:2', 'body' => 'required|string|max_bytes:6', 'interval' => 'integer|between:5,10', 'state' => 'string|in:hidden,published', 'items' => 'array|max:2'];
$constraints = \TinyCat\Http\Validator::constraints($definition);
$assert($constraints['title']['max_length'] === 2 && $constraints['body']['max_bytes'] === 6 && !isset($constraints['body']['max_length']), 'Constraints preserve character and byte dimensions independently.');
$assert($constraints['interval']['min'] === 5 && $constraints['interval']['max'] === 10 && $constraints['state']['values'] === ['hidden', 'published'] && $constraints['items']['max_items'] === 2, 'Numeric bounds, enums and collection sizes derive from the validation definition.');
$assert(\TinyCat\Http\Validator::validate(['title' => '😀😀', 'body' => 'ěěě'], $definition) === [], 'Unicode characters and UTF-8 bytes use the declared units at the exact boundary.');
$errors = \TinyCat\Http\Validator::validate(['body' => 'ěěěě'], ['body' => $definition['body']], structured: true);
$assert($errors['body'][0]['code'] === 'validation_max_bytes', 'A multibyte overflow uses the common field issue contract.');
$throws(static fn () => \TinyCat\Http\Validator::constraints(['field' => 'required']), LogicException::class);
$throws(static fn () => \TinyCat\Http\Validator::constraints(['field' => 'string|integer']), LogicException::class);
$combined = \TinyCat\Http\Validator::constraints(['text' => 'string|min:3|min:1|max:8|max:10|max_bytes:12|max_bytes:20|in:one,two|in:two,three', 'number' => 'integer|between:5,20|between:1,10']);
$assert($combined['text']['min_length'] === 3 && $combined['text']['max_length'] === 8 && $combined['text']['max_bytes'] === 12 && $combined['text']['values'] === ['two'] && $combined['number']['min'] === 5 && $combined['number']['max'] === 10, 'Combined constraints retain the strongest bounds and intersect allowed values.');
$throws(static fn () => \TinyCat\Http\Validator::validate([], ['field' => 'max_bytes:1.5']), LogicException::class);
$normalizeIssues = new ReflectionMethod(\TinyCat\Http\Response::class, 'issues');
$issues = $normalizeIssues->invoke(null, ['title' => ['domain' => \TinyCat\Http\Response::issue('title_invalid', 'Invalid title.', ['field' => 'other', 'max' => 200])]]);
$assert(array_is_list($issues['title']), 'Field issues always serialize as a JSON list, regardless of contributor array keys.');
$assert($issues['title'][0]['params'] === ['field' => 'title', 'max' => 200], 'The core owns the actual validation field even if a contributor supplies a conflicting parameter.');
$throws(static fn () => \TinyCat\Http\Response::issue('translation.key', 'Invalid.'), LogicException::class);
$throws(static fn () => $normalizeIssues->invoke(null, ['title' => [['code' => 'title_invalid', 'message' => []]]]), LogicException::class);
$throws(static fn () => $normalizeIssues->invoke(null, ['title' => (object) ['message' => 'Invalid.']]), LogicException::class);
$pageReads = 0;
// Route mechanics are independent of the developer installation's web setting.
Router::configurePublicWeb(static fn (): bool => true);
Router::registerModule(static function () use (&$pageReads): void {
    Router::page('fixture.pages', '/fixture-page/{id:[1-9][0-9]*}', static fn (string $id): string => $id, static function (string $id) use (&$pageReads): array { $pageReads++; return ['id' => (int) $id]; }, ['q']);
});
$assert($pageReads === 0, 'Page registration never evaluates its resource callback.');
$assert(Router::pageResource('fixture.pages', '/fixture-page/12') === ['id' => 12] && $pageReads === 1, 'Page resources resolve through the registered HTTP route parameters.');
$assert(Router::pageResource('other.owner', '/fixture-page/12') === null && Router::pageResource('fixture.pages', '/fixture-page/0') === null, 'Page resources respect ownership and the canonical path constraint.');
$definitions = Router::pageDefinitions('fixture.pages');
$assert($definitions === [['pattern' => '^/fixture-page/(?:[1-9][0-9]*)$', 'query' => ['q']]], 'Client metadata is derived from the HTTP route and its query allowlist.');
$throws(static function (): void { Router::registerModule(static function (): void { foreach ([1, 2] as $_) { Router::page('fixture.rejected', '/duplicate-page', static fn (): string => '', static fn (): array => []); } }); }, InvalidArgumentException::class);
$assert(Router::pageDefinitions('fixture.rejected') === [], 'A rejected provider rolls back page metadata with its HTTP routes.');
$sourceInput = \TinyCat\Social\Bots\Administration::prepareSourceInput(['bot_user_id' => 3, 'name' => str_repeat("\u{11B}", 60), 'feed_url' => 'https://example.test/feed', 'post_template' => str_repeat("\u{11B}", 1000)]);
$sourceRules = \TinyCat\Social\Bots\Administration::sourceRules();
$assert(\TinyCat\Http\Validator::validate($sourceInput, $sourceRules) === [], 'Social source rules accept exact UTF-8 byte limits and supply the declared interval defaults.');
foreach (['name' => str_repeat("\u{11B}", 61), 'post_template' => str_repeat("\u{11B}", 1001), 'interval_minutes' => 4, 'publication_interval_minutes' => 43201] as $field => $value) {
    $assert(isset(\TinyCat\Http\Validator::validate(array_replace($sourceInput, [$field => $value]), $sourceRules)[$field]), 'Social source rules reject overflow using their shared field definition: ' . $field);
}
$throws(static fn () => Router::route('ANY', '/unbounded', static fn (): null => null), InvalidArgumentException::class);

$routeValues = [];
Router::page('fixture.encoded', '/fixture-encoded/{value}',
    static function (string $value) use (&$routeValues): void { $routeValues[] = $value; },
    static fn (string $value): array => ['value' => $value]);
foreach (['hello%20world' => 'hello world', '%25' => '%', '%252F' => '%2F', '%255C' => '%5C', '%252e%252e' => '%2e%2e', '%C5%BE' => "\u{17E}"] as $encoded => $expected) {
    $pathOwner = new Request(serverData: ['REQUEST_URI' => '/fixture-encoded/' . $encoded]);
    $path = $pathOwner->path();
    $assert(Request::normalizePath($path) === $path, 'Internal path normalization is idempotent: ' . $encoded);
    $assert(Router::dispatch($path, 'GET') && end($routeValues) === $expected, 'Native dispatch decodes at the request boundary only: ' . $encoded);
    $assert(Router::pageResource('fixture.encoded', $path) === ['value' => $expected], 'Page resources and native dispatch receive identical literal segments: ' . $encoded);
}
foreach (['%2F', '%5C', 'one%2Ftwo'] as $encoded) {
    $path = (new Request(serverData: ['REQUEST_URI' => '/fixture-encoded/' . $encoded]))->path();
    $assert(!Router::dispatch($path, 'GET') && Router::pageResource('fixture.encoded', $path) === null, 'Decoded separators cannot become one captured segment: ' . $encoded);
}
$assert(!Router::isApiPath((new Request(serverData: ['REQUEST_URI' => '/%2561pi/items']))->path()), 'API classification does not decode a second time.');
Router::route('GET', '/fixture-regex/{value:\d{1,3}}', static function (string $value) use (&$routeValues): void { $routeValues[] = $value; });
$assert(Router::dispatch('/fixture-regex/123', 'GET') && end($routeValues) === '123'
    && !Router::dispatch('/fixture-regex/1234', 'GET'), 'Route definitions preserve regex escapes and quantifiers.');
Router::route('GET', '/fixture-percent/%2F', static function () use (&$routeValues): void { $routeValues[] = 'percent literal'; });
$assert(Router::dispatch((new Request(serverData: ['REQUEST_URI' => '/fixture-percent/%252F']))->path(), 'HEAD')
    && end($routeValues) === 'percent literal', 'Literal percent route definitions and implicit HEAD share the native matcher.');
$registered = (new ReflectionProperty(Router::class, 'routes'))->getValue();
$assert(end($registered)['methods'] === ['GET', 'HEAD'], 'The registered method metadata includes implicit HEAD, also used by Allow.');

Session::start();
$assert(Session::withoutLock(static fn (): bool => session_status() !== PHP_SESSION_ACTIVE), 'Slow operations run without a session lock.');
$assert(session_status() === PHP_SESSION_ACTIVE, 'The session is reopened after slow operations.');
$throws(static fn () => Session::withoutLock(static function (): never { throw new RuntimeException('I/O failure'); }), RuntimeException::class);
$assert(session_status() === PHP_SESSION_ACTIVE, 'The session is reopened after a failed operation.');
$assert(\TinyCat\Core\Security\Csrf::token() === Csrf::token(), 'Legacy and module routes share exactly one CSRF token.');
$assert(Csrf::verify(\TinyCat\Core\Security\Csrf::token()) && !Csrf::verify('incorrect'), 'CSRF verification belongs to shared security infrastructure.');
\TinyCat\Http\Session::flash('foundation', 'once');
$assert(Session::flash('foundation') === 'once' && \TinyCat\Http\Session::flash('foundation') === null, 'Flash state is consumed once across the compatibility boundary.');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    \TinyCat\Core\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Core\Identity\Authentication::reset(); \TinyCat\Database\Connection::set($database);
    $assert(Connection::get() === $database && \TinyCat\Database\Connection::get() === $database, 'Core and modules share one PDO connection.');
    Connection::exec('CREATE TABLE records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL)');
    $id = Connection::insert('records', ['name' => 'first']);
    $assert(\TinyCat\Database\Connection::find('records', ['id' => $id])['name'] === 'first', 'Legacy reads observe module writes.');
    $assert(Query::select('SELECT name FROM records')->whereIn('id', [$id])->value() === 'first', 'The namespaced query builder executes against the shared connection.');
    $assert(\TinyCat\Database\Transactions::run(static function (): int {
        Connection::insert('records', ['name' => 'outer']);
        Transactions::run(static fn (): string => Connection::insert('records', ['name' => 'inner']));
        return Connection::count('records');
    }) === 3 && !$database->inTransaction(), 'Nested legacy/module writes commit once.');
    $throws(static function (): void {
        Transactions::run(static function (): void {
            Connection::insert('records', ['name' => 'rollback-outer']);
            \TinyCat\Database\Transactions::run(static function (): void {
                Connection::insert('records', ['name' => 'rollback-inner']);
                throw new DomainException('rollback');
            });
        });
    }, DomainException::class);
    $assert(Connection::count('records') === 3 && !$database->inTransaction(), 'A nested failure rolls back the complete aggregate.');
    $throws(static fn () => Connection::delete('records', []), InvalidArgumentException::class);
    $throws(static fn () => (new Query('SELECT * FROM records'))->whereIn('id; DROP TABLE records', [1]), InvalidArgumentException::class);
    try { Connection::insert('records', ['name' => 'first']); } catch (PDOException $exception) {
        $assert(Connection::uniqueViolation($exception) && \TinyCat\Database\Connection::uniqueViolation($exception), 'Unique constraint handling is shared.');
    }
    $effects = [];
    $failure = new RuntimeException('injected after-commit failure');
    try {
        Transactions::run(static function () use (&$effects, $failure): void {
            Connection::insert('records', ['name' => 'committed-effects']);
            Transactions::afterCommit(static function () use ($failure): void { throw $failure; });
            Transactions::afterCommit(static function () use (&$effects): void {
                Transactions::run(static function () use (&$effects): void {
                    Transactions::afterCommit(static function () use (&$effects): void { $effects[] = 'independent transaction'; });
                });
            });
            Transactions::afterCommit(static function () use (&$effects): void { $effects[] = 'last effect'; });
            Transactions::afterCommit(static function (): void { throw new DomainException('another failed effect'); });
        });
        $assert(false, 'After-commit failures must be reported.');
    } catch (\TinyCat\Database\AfterCommitFailed $exception) {
        $assert(count($exception->failures) === 2 && $exception->getPrevious() === $failure, 'Post-commit errors preserve every failure and distinguish a successful database commit.');
    }
    $assert(Connection::find('records', ['name' => 'committed-effects']) !== null && !$database->inTransaction(), 'An effect failure does not undo committed data.');
    $assert($effects === ['independent transaction', 'last effect'], 'All effects run in order, including an effect opening its own transaction.');
    $throws(static function () use (&$effects): void {
        Transactions::run(static function () use (&$effects): void {
            Transactions::afterCommit(static function () use (&$effects): void { $effects[] = 'rolled back'; });
            throw new DomainException('rollback effects');
        });
    }, DomainException::class);
    $assert($effects === ['independent transaction', 'last effect'], 'Rollback discards the effect queue.');
    Transactions::run(static function () use (&$effects): void {
        Transactions::afterCommit(static function () use (&$effects): void { $effects[] = 'next transaction'; });
    });
    $assert(end($effects) === 'next transaction', 'A subsequent transaction gets an independent clean queue.');
    $assert(\TinyCat\Core\Database\Statements::quote('Order') === '`Order`'
        && \TinyCat\Core\Database\Statements::quote('_column', false) === '"_column"'
        && \TinyCat\Core\Database\Statements::identifier('owner.records', true) === 'owner.records', 'Shared identifier validation preserves qualified names and driver-specific quoting.');
    foreach (["name\n", 'a; DROP TABLE records', 'a.b', ''] as $identifier) {
        $throws(static fn () => \TinyCat\Core\Database\Statements::quote($identifier), InvalidArgumentException::class);
    }
    $once = [];
    Transactions::run(static function () use (&$once): void {
        Transactions::afterCommit(static function () use (&$once): void { $once[] = 'first'; }, 'fixture.once');
        Transactions::run(static function () use (&$once): void {
            Transactions::afterCommit(static function () use (&$once): void { $once[] = 'duplicate'; }, 'fixture.once');
        });
        Transactions::afterCommit(static function () use (&$once): void { $once[] = 'independent'; }, 'fixture.other');
    });
    $assert($once === ['first', 'independent'], 'Named effects coalesce across nested writes and preserve first-registration order.');
    $throws(static function () use (&$once): void {
        Transactions::run(static function () use (&$once): void {
            Transactions::afterCommit(static function () use (&$once): void { $once[] = 'rollback'; }, 'fixture.once');
            throw new DomainException('named effects rollback');
        });
    }, DomainException::class);
    Transactions::run(static function () use (&$once): void {
        Transactions::afterCommit(static function () use (&$once): void { $once[] = 'next'; }, 'fixture.once');
    });
    $assert($once === ['first', 'independent', 'next'], 'Rollback and a previous commit cannot suppress named effects in later transactions.');
    $probe = new class('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
        /** @var list<string> */
        public array $reads = [];
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $this->reads[] = $query;
            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }
        /** @param array<mixed> $options */
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->reads[] = $query;
            return parent::prepare($query, $options);
        }
    };
    $probe->exec('CREATE TABLE ready_table (id INTEGER)');
    $readiness = new ReflectionProperty(\TinyCat\Database\Schema::class, 'accountReady');
    $accountReady = $readiness->getValue();
    $readiness->setValue(null, static fn (): bool => false);
    Connection::set($probe);
    try {
        $status = \TinyCat\Database\Schema::app_db_status(['ready_table']);
        $assert($status['connected'] && $status['missing_tables'] === [] && count($probe->reads) === 1
            && !in_array('SELECT 1', $probe->reads, true), 'Schema inspection proves connectivity with one query and no redundant ping.');
        $status = \TinyCat\Database\Schema::app_db_status(['missing_table']);
        $assert($status['connected'] && $status['missing_tables'] === ['missing_table'] && !$status['ready'], 'Missing tables still produce the installation readiness failure.');
        $probe->reads = [];
        $status = \TinyCat\Database\Schema::app_db_status([]);
        $assert($status['connected'] && $probe->reads === ['SELECT 1'], 'An empty schema requirement still checks connectivity.');
        $probe->exec('CREATE TABLE module_installations (module_id TEXT PRIMARY KEY, version INTEGER, status TEXT)');
        $probe->exec('CREATE TABLE another_table (id INTEGER)');
        $probe->exec("INSERT INTO module_installations VALUES ('fixture.one', 1, 'ready'), ('fixture.two', 1, 'ready')");
        $schemas = [];
        foreach (['fixture.one' => 'ready_table', 'fixture.two' => 'another_table'] as $owner => $table) {
            $schemas[$owner] = new \TinyCat\Core\Modules\ModuleSchema(1, [$table], static function (PDO $pdo): void {},
                [new \TinyCat\Core\Modules\SchemaMigration(1, $owner . '.initial', __FILE__)]);
        }
        $probe->reads = [];
        $assert(\TinyCat\Database\ModuleSchemas::ready($probe, $schemas) && count($probe->reads) === 2, 'Multiple module streams use one table inventory and one batched state read.');
        $probe->exec("UPDATE module_installations SET status = 'failed' WHERE module_id = 'fixture.two'");
        $assert(!\TinyCat\Database\ModuleSchemas::ready($probe, $schemas), 'A previously ready stream is immediately rejected after a state change.');
        $probe->exec("UPDATE module_installations SET status = 'ready', version = 2 WHERE module_id = 'fixture.two'");
        $assert(!\TinyCat\Database\ModuleSchemas::ready($probe, $schemas), 'Readiness requires the exact declared version.');
        $probe->exec("UPDATE module_installations SET version = 1 WHERE module_id = 'fixture.two'");
        $probe->exec('DROP TABLE another_table');
        $assert(!\TinyCat\Database\ModuleSchemas::ready($probe, $schemas), 'A missing declared table is detected without stale readiness caching.');
        $probe->exec("DELETE FROM module_installations WHERE module_id = 'fixture.one'");
        $assert(!\TinyCat\Database\ModuleSchemas::ready($probe, ['fixture.one' => $schemas['fixture.one']]), 'Missing installation state is never adopted implicitly.');
        $probe->reads = [];
        $assert(\TinyCat\Database\ModuleSchemas::ready($probe, []) && $probe->reads === [], 'Empty module selection does not access the database.');
    } finally {
        Connection::set($database);
        $readiness->setValue(null, $accountReady);
    }
    $configuration = \TinyCat\Support\Config::get();
    $mismatched = $configuration;
    $mismatched['database']['driver'] = 'mysql';
    (new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $mismatched);
    try {
        $assert(Connection::driver() === 'sqlite', 'Driver selection follows the injected connection rather than configuration.');
        $assert(\TinyCat\Database\Schema::app_existing_tables(['records', 'missing']) === ['records'], 'Schema inspection uses the actual connection driver.');
    } finally {
        (new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, $configuration);
    }
    Connection::exec('CREATE TABLE query_items (id INTEGER PRIMARY KEY, category TEXT)');
    Connection::exec("INSERT INTO query_items VALUES (1, 'a'), (2, 'a'), (3, 'a'), (4, 'b'), (5, 'b'), (6, 'c')");
    $filtered = (new Query('SELECT id FROM query_items'))->whereIn('category', ['a', 'b'])->order('id DESC')->limit(2, 1);
    $assert($filtered->count() === 5 && array_column($filtered->all(), 'id') === [4, 3], 'Counting ignores pagination without mutating the paginated data query.');
    $grouped = (new Query('SELECT category, COUNT(*) AS total FROM query_items'))->group('category')->order('total DESC')->limit(1);
    $assert($grouped->count() === 3 && count($grouped->all()) === 1, 'Grouped query counts count groups rather than source rows.');
    $assert((new Query('SELECT DISTINCT category FROM query_items'))->count() === 3, 'Distinct projections retain their counting semantics.');
    $assert((new Query('SELECT id FROM query_items'))->whereIn('id', [])->count() === 0, 'An empty IN filter safely counts zero rows.');
    $displayCalls = 0;
    $database->sqliteCreateFunction('display_value', static function (int $id) use (&$displayCalls): int { $displayCalls++; return $id; });
    $display = (new Query('SELECT display_value(id) AS label FROM query_items'))->where('id > ?', 2)->limit(1);
    $assert($display->count('SELECT id FROM query_items') === 4 && $displayCalls === 0, 'A lightweight count preserves filters without evaluating display projections.');
    $assert(count($display->all()) === 1 && $displayCalls === 1, 'The original display projection remains available after counting.');
    $literal = '100%_!';
    Connection::exec('CREATE TABLE literal_search (label TEXT)');
    Connection::insert('literal_search', ['label' => $literal]);
    Connection::insert('literal_search', ['label' => '100X_!']);
    $assert((new Query('SELECT label FROM literal_search'))->where("label LIKE ? ESCAPE '!'", Connection::escapeLike($literal))->count() === 1, 'LIKE escaping treats percent, underscore and the escape character literally.');
    Connection::exec('CREATE TABLE atomic_values (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, value TEXT, extra INTEGER CHECK (extra >= 0))');
    Connection::upsert('atomic_values', ['name' => 'one'], ['value' => 'initial', 'extra' => 7]);
    $firstId = Connection::find('atomic_values', ['name' => 'one'])['id'];
    Connection::upsert('atomic_values', ['name' => 'one'], ['value' => null]);
    $saved = Connection::find('atomic_values', ['name' => 'one']);
    $assert(Connection::count('atomic_values') === 1 && $saved['id'] === $firstId && $saved['value'] === null && $saved['extra'] === 7, 'Atomic upsert preserves row identity and omitted columns while allowing null data.');
    $throws(static fn () => Connection::upsert('atomic_values', [], ['value' => 'x']), InvalidArgumentException::class);
    $throws(static fn () => Connection::upsert('atomic_values', ['name' => null], ['value' => 'x']), InvalidArgumentException::class);
    $throws(static fn () => Connection::upsert('atomic_values', ['name' => 'one'], ['name' => 'two']), InvalidArgumentException::class);
    $throws(static fn () => Connection::upsert('atomic_values', ['name' => 'one'], ['extra' => -1]), PDOException::class);
    $assert(Connection::find('atomic_values', ['name' => 'one'])['extra'] === 7, 'Non-unique constraint failures propagate without corrupting the existing upsert row.');
}
echo "PASS shared HTTP, session and database infrastructure ({$checks} checks)\n";
