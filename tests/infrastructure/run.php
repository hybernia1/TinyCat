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
}
echo "PASS shared HTTP, session and database infrastructure ({$checks} checks)\n";
