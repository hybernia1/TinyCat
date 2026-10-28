<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Moderation\Restrictions;
use TinyCat\Security\RateLimiter;
use TinyCat\Status\Posts;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP rate limiter: pdo_sqlite unavailable.\n";
    exit(0);
}
(new ReflectionProperty(\TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, [
    'datetime' => ['timezone' => 'UTC'],
    'cache' => ['driver' => 'filesystem'],
]);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Connection::set($pdo);
$pdo->exec('CREATE TABLE ip_action_limits (ip_address VARCHAR(45), action_name VARCHAR(40), bucket_start TEXT, action_count INTEGER, updated_at TEXT, PRIMARY KEY(ip_address, action_name, bucket_start))');
$pdo->exec('CREATE TABLE content (author_id INTEGER)');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$alice = ['id' => 1, 'role' => 'user', 'created_at' => date('Y-m-d H:i:s')];
$bob = array_replace($alice, ['id' => 2]);
Restrictions::moderation_record_action($alice, 'post');
Restrictions::moderation_record_action($alice, 'post');
$assert(Restrictions::moderation_action_count($alice, 'post') === 2, 'Successful actions accumulate for the account.');
$assert(Restrictions::moderation_action_count($bob, 'post') === 0, 'Accounts on the same IP do not share a budget.');
$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
$assert(Restrictions::moderation_action_count($alice, 'post') === 2, 'Changing IP cannot reset the account budget.');
$assert(Restrictions::moderation_action_count($alice, 'comment') === 0, 'Different actions have independent budgets.');
RateLimiter::rate_limit_ip_action_record('email-check', 60);
$assert(RateLimiter::rate_limit_ip_action_count('email-check', 60) === 1, 'Public IP limits still count requests.');
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$assert(RateLimiter::rate_limit_ip_action_count('email-check', 60) === 0, 'Public IP budgets remain isolated by IP.');
RateLimiter::subject_action_record('user:1', 'expired', 3600);
$pdo->exec("UPDATE ip_action_limits SET bucket_start = '2000-01-01 00:00:00' WHERE action_name = 'expired'");
$assert(RateLimiter::subject_action_count('user:1', 'expired') === 0, 'Expired buckets do not block current actions.');
$assert(RateLimiter::window_retry_after(3600) >= 1 && RateLimiter::window_retry_after(3600) <= 3600, 'Wait time stays within the fixed window.');
\TinyCat\Http\Session::start();
$_SESSION['_action_timestamps'] = [];
[$create, $interval] = Posts::status_session_interval_rule('create');
$assert(RateLimiter::session_action_retry_after('status_' . $create, $interval, false) === 0, 'A new action passes the preflight check.');
$assert(empty($_SESSION['_action_timestamps']), 'Preflight does not consume a cooldown when validation fails.');
RateLimiter::session_action_retry_after('status_' . $create, $interval);
$assert(RateLimiter::session_action_retry_after('status_' . $create, $interval, false) > 0, 'Successful actions start a cooldown.');
foreach (['update', 'delete', 'comment', 'comment_update', 'comment_delete'] as $action) {
    [$key, $ms] = Posts::status_session_interval_rule($action);
    $assert(RateLimiter::session_action_retry_after('status_' . $key, $ms, false) === 0, 'Publishing does not block ' . $action . '.');
}
[$comment] = Posts::status_session_interval_rule('comment');
[$edit] = Posts::status_session_interval_rule('comment_update');
[$delete] = Posts::status_session_interval_rule('comment_delete');
$assert(count(array_unique([$comment, $edit, $delete])) === 3, 'Comment creation, editing and deletion use separate cooldowns.');
session_write_close();
$assert(RateLimiter::consume('user:1', 'atomic', 3600, 2), 'The first reservation is accepted.');
$assert(RateLimiter::consume('user:1', 'atomic', 3600, 2), 'The last permitted reservation is accepted.');
$assert(!RateLimiter::consume('user:1', 'atomic', 3600, 2), 'The next reservation is rejected.');
$assert(RateLimiter::subject_action_count('user:1', 'atomic') === 2, 'Rejected reservations do not increase the counter.');
$assert(RateLimiter::consume('user:2', 'atomic', 3600, 2), 'A different account retains its budget.');
$rule = \TinyCat\Security\RateLimitPolicy::requestRule('upload');
for ($i = 0; $i < $rule['max']; $i++) {
    $assert(RateLimiter::request_retry_after('upload', 50) === 0, 'Upload attempts up to the policy limit are accepted.');
}
$assert(RateLimiter::request_retry_after('upload', 50) > 0, 'Upload attempts beyond the policy limit are blocked.');
$assert(RateLimiter::request_retry_after('upload', 51) === 0, 'Uploads by another account on the same IP are independent.');
try { RateLimiter::request_retry_after('upload'); throw new RuntimeException('Missing actor accepted.'); }
catch (InvalidArgumentException) { $checks++; }
try { \TinyCat\Security\RateLimitPolicy::requestRule('typo'); throw new RuntimeException('Unknown action accepted.'); }
catch (InvalidArgumentException) { $checks++; }
foreach (['admin', 'bot'] as $role) {
    $exempt = ['id' => 50, 'role' => $role];
    \TinyCat\Users\Authentication::reset($exempt, true);
    $assert(\TinyCat\Security\RateLimitPolicy::isExempt($exempt), $role . ' is exempt by policy.');
    $assert(RateLimiter::request_retry_after('upload', 50) === 0, $role . ' bypasses exhausted request budgets.');
    $assert(RateLimiter::session_action_retry_after('status_' . $create, $interval) === 0, $role . ' bypasses active cooldowns.');
    $before = RateLimiter::subject_action_count('user:50', 'post');
    Restrictions::moderation_record_action($exempt, 'post');
    $assert(RateLimiter::subject_action_count('user:50', 'post') === $before, $role . ' does not consume content quotas.');
    $assert(Restrictions::moderation_action_count($exempt, 'post') === 0, $role . ' has no enforced content quota.');
    \TinyCat\Search\Guard::suggestions();
    $assert(!isset($_SESSION['_tinycat_suggestion_guard']), $role . ' does not consume the suggestion quota.');
}
\TinyCat\Users\Authentication::reset(null, true);
echo "PASS rate limiter isolation and cooldowns ({$checks} checks)\n";
