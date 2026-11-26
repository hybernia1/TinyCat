<?php
declare(strict_types=1);
require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../App/Core/Runtime/autoload.php';

use TinyCat\Http\Session;
use TinyCat\Core\Security\Csrf;

$directory = (string) getenv('TINYCAT_CACHE_DIRECTORY') . '/sessions';
mkdir($directory, 0700, true);
session_save_path($directory);
session_id('tinycat-scope-test');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$token = Csrf::token();
$assert(session_status() !== PHP_SESSION_ACTIVE && $token === Csrf::token(), 'CSRF creation persists its token and releases its own lock.');
Session::flash('success', 'saved');
$assert(session_status() !== PHP_SESSION_ACTIVE && Session::flash('success') === 'saved' && Session::flash('success') === null, 'Flash writes and one-shot reads each release the lock.');
Session::flash('success', 'batch'); Session::flash('other', 'preserved');
$assert(Session::pullFlashes(['success', 'error']) === ['success' => 'batch', 'error' => null] && Session::flash('other') === 'preserved', 'Batch consumption preserves unrelated flash messages.');
try { Session::scoped(static function (): void { $_SESSION['exception'] = 'persisted'; throw new RuntimeException('fixture'); }); }
catch (RuntimeException $error) { $assert($error->getMessage() === 'fixture', 'The original exception propagates.'); }
$assert(session_status() !== PHP_SESSION_ACTIVE, 'Exceptions release an owned session.');
Session::start();
$assert($_SESSION['exception'] === 'persisted', 'Session writes survive exceptions.');
Session::scoped(static function () use ($assert): void { Csrf::token(); Session::flash('nested', 'value'); $assert(session_status() === PHP_SESSION_ACTIVE, 'Nested scopes preserve their caller-owned session.'); });
$assert(session_status() === PHP_SESSION_ACTIVE, 'An outer caller keeps control of its write scope.');
session_write_close();
$assert(Csrf::verify($token), 'A reopened session still verifies the persisted CSRF token.');
session_write_close();
$previousToken = $token;
Session::scoped(static function (): void { unset($_SESSION['_csrf']); });
$token = Csrf::token();
$assert($token !== $previousToken && Csrf::verify($token), 'A changed session snapshot invalidates the token cache.');
session_write_close();
\TinyCat\Core\Identity\Authentication::reset();
$assert(\TinyCat\Core\Identity\Authentication::auth() === null && session_status() !== PHP_SESSION_ACTIVE, 'Anonymous identity resolution does not hold its session during later I/O.');
echo "PASS bounded session ownership, CSRF and flash persistence ({$checks} checks)\n";
