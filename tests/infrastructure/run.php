<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

use \TinyCat\Database\Connection;
use \TinyCat\Database\Query;
use \TinyCat\Database\Transactions;
use \TinyCat\Http\Request;
use \TinyCat\Http\Router;
use \TinyCat\Security\Csrf;
use \TinyCat\Http\Session;

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';
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
$throws(static fn () => Router::route('ANY', '/unbounded', static fn (): null => null), InvalidArgumentException::class);

Session::start();
$assert(Session::withoutLock(static fn (): bool => session_status() !== PHP_SESSION_ACTIVE), 'Slow operations run without a session lock.');
$assert(session_status() === PHP_SESSION_ACTIVE, 'The session is reopened after slow operations.');
$throws(static fn () => Session::withoutLock(static function (): never { throw new RuntimeException('I/O failure'); }), RuntimeException::class);
$assert(session_status() === PHP_SESSION_ACTIVE, 'The session is reopened after a failed operation.');
$assert(\TinyCat\Security\Csrf::token() === Csrf::token(), 'Legacy and module routes share exactly one CSRF token.');
$assert(Csrf::verify(\TinyCat\Security\Csrf::token()) && !Csrf::verify('incorrect'), 'CSRF verification belongs to shared security infrastructure.');
\TinyCat\Http\Session::flash('foundation', 'once');
$assert(Session::flash('foundation') === 'once' && \TinyCat\Http\Session::flash('foundation') === null, 'Flash state is consumed once across the compatibility boundary.');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
    $assert(Connection::get() === $database && \TinyCat\Database\Connection::get() === $database, 'Core and modules share one PDO connection.');
    Connection::exec('CREATE TABLE records (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL)');
    $id = Connection::insert('records', ['name' => 'first']);
    $assert(\TinyCat\Database\Connection::find('records', ['id' => $id])['name'] === 'first', 'Legacy reads observe module writes.');
    $assert((new Query('SELECT name FROM records'))->whereIn('id', [$id])->value() === 'first', 'The namespaced query builder executes against the shared connection.');
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
