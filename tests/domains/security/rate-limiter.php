<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php';

use TinyCat\Database\Connection;
use TinyCat\Social\Moderation\Restrictions;
use TinyCat\Core\Security\RateLimiter;

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP rate limiter: pdo_mysql unavailable.\n";
    exit(0);
}
(new ReflectionProperty(\TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(\TinyCat\Support\Config::class, 'config'))->setValue(null, [
    'datetime' => ['timezone' => 'UTC'],
    'cache' => ['driver' => 'filesystem'],
]);
$testDatabaseConfig = tinycat_test_database_config();
$pdo = new class ($testDatabaseConfig['dsn'], $testDatabaseConfig['user'], $testDatabaseConfig['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]) extends PDO {
    public int $reputationQueries = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'SELECT COUNT(*) FROM social_posts')) { $this->reputationQueries++; }
        return parent::prepare($query, $options);
    }
};
Connection::set($pdo);
$pdo->exec('CREATE TABLE ip_action_limits (ip_address VARCHAR(45), action_name VARCHAR(40), bucket_start DATETIME, action_count INTEGER, updated_at DATETIME, PRIMARY KEY(ip_address, action_name, bucket_start))');
$pdo->exec('CREATE TABLE social_posts (author_id INT UNSIGNED, kind VARCHAR(40) DEFAULT (\'post\'))');
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
[$create, $interval] = \TinyCat\Social\Moderation\ActionPolicy::cooldown('create');
$assert(RateLimiter::session_action_retry_after('status_' . $create, $interval, false) === 0, 'A new action passes the preflight check.');
$assert(empty($_SESSION['_action_timestamps']), 'Preflight does not consume a cooldown when validation fails.');
RateLimiter::session_action_retry_after('status_' . $create, $interval);
$assert(RateLimiter::session_action_retry_after('status_' . $create, $interval, false) > 0, 'Successful actions start a cooldown.');
foreach (['update', 'delete', 'comment', 'comment_update', 'comment_delete'] as $action) {
    [$key, $ms] = \TinyCat\Social\Moderation\ActionPolicy::cooldown($action);
    $assert(RateLimiter::session_action_retry_after('status_' . $key, $ms, false) === 0, 'Publishing does not block ' . $action . '.');
}
[$comment] = \TinyCat\Social\Moderation\ActionPolicy::cooldown('comment');
[$edit] = \TinyCat\Social\Moderation\ActionPolicy::cooldown('comment_update');
[$delete] = \TinyCat\Social\Moderation\ActionPolicy::cooldown('comment_delete');
$assert(count(array_unique([$comment, $edit, $delete])) === 3, 'Comment creation, editing and deletion use separate cooldowns.');
session_write_close();
$assert(RateLimiter::consume('user:1', 'atomic', 3600, 2), 'The first reservation is accepted.');
$assert(RateLimiter::consume('user:1', 'atomic', 3600, 2), 'The last permitted reservation is accepted.');
$assert(!RateLimiter::consume('user:1', 'atomic', 3600, 2), 'The next reservation is rejected.');
$assert(RateLimiter::subject_action_count('user:1', 'atomic') === 2, 'Rejected reservations do not increase the counter.');
$assert(RateLimiter::consume('user:2', 'atomic', 3600, 2), 'A different account retains its budget.');
$rule = \TinyCat\Core\Security\RateLimitPolicy::requestRule('upload');
for ($i = 0; $i < $rule['max']; $i++) {
    $assert(RateLimiter::request_retry_after('upload', 50) === 0, 'Upload attempts up to the policy limit are accepted.');
}
$assert(RateLimiter::request_retry_after('upload', 50) > 0, 'Upload attempts beyond the policy limit are blocked.');
$assert(RateLimiter::request_retry_after('upload', 51) === 0, 'Uploads by another account on the same IP are independent.');
try { RateLimiter::request_retry_after('upload'); throw new RuntimeException('Missing actor accepted.'); }
catch (InvalidArgumentException) { $checks++; }
try { \TinyCat\Core\Security\RateLimitPolicy::requestRule('typo'); throw new RuntimeException('Unknown action accepted.'); }
catch (InvalidArgumentException) { $checks++; }
foreach (['admin'] as $role) {
    $exempt = ['id' => 50, 'role' => $role];
    \TinyCat\Core\Identity\Authentication::reset($exempt, true);
    $assert(\TinyCat\Core\Security\RateLimitPolicy::isExempt($exempt), $role . ' is exempt by policy.');
    $assert(RateLimiter::request_retry_after('upload', 50) === 0, $role . ' bypasses exhausted request budgets.');
    $assert(RateLimiter::session_action_retry_after('status_' . $create, $interval) === 0, $role . ' bypasses active cooldowns.');
    $before = RateLimiter::subject_action_count('user:50', 'post');
    Restrictions::moderation_record_action($exempt, 'post');
    $assert(RateLimiter::subject_action_count('user:50', 'post') === $before, $role . ' does not consume content quotas.');
    $assert(Restrictions::moderation_action_count($exempt, 'post') === 0, $role . ' has no enforced content quota.');
    \TinyCat\Social\Search\Guard::suggestions();
    $assert(!isset($_SESSION['_tinycat_suggestion_guard']), $role . ' does not consume the suggestion quota.');
}
$bot = ['id' => 50, 'role' => 'bot'];
\TinyCat\Core\Identity\Authentication::reset($bot, true);
$assert(!\TinyCat\Core\Security\RateLimitPolicy::isExempt($bot), 'Bot has no global core exemption.');
$assert(RateLimiter::request_retry_after('upload', 50) > 0, 'Bot cannot bypass the exhausted upload budget.');
$assert(RateLimiter::session_action_retry_after('status_' . $create, $interval, false) > 0, 'Bot cannot bypass a session cooldown.');
$assert(\TinyCat\Social\Moderation\ActionPolicy::isExempt($bot, 'post'), 'Social permits bot publication imports.');
foreach (['comment', 'like', 'report', 'search', 'other'] as $action) {
    $assert(!\TinyCat\Social\Moderation\ActionPolicy::isExempt($bot, $action), 'Bot exemption does not apply to ' . $action);
}
Restrictions::moderation_record_action($bot, 'post');
$assert(Restrictions::moderation_action_count($bot, 'post') === 0, 'Bot imports do not consume publication quotas.');
Restrictions::moderation_record_action($bot, 'comment');
$assert(Restrictions::moderation_action_count($bot, 'comment') === 1, 'Other bot actions still consume quotas.');
\TinyCat\Core\Identity\Authentication::reset(null, true);
$pdo->exec("INSERT INTO social_posts(kind, author_id) VALUES ('post', 1), ('post', 1), ('post', 1)");
for ($article = 0; $article < 20; $article++) { $pdo->exec("INSERT INTO social_posts(kind, author_id) VALUES ('article', 1)"); }
$assert(Restrictions::moderation_user_post_count(1) === 3, 'Only status publications count towards Social reputation');
$assert(Restrictions::moderation_user_reputation(['id' => 1, 'role' => 'user', 'created_at' => date('Y-m-d H:i:s', time() - 8 * 86400)]) === 'normal', 'Articles cannot promote Social reputation to trusted');

$reputationActor = ['id' => 101, 'role' => 'user', 'created_at' => date('Y-m-d H:i:s')];
$beforeQueries = $pdo->reputationQueries;
foreach (['post', 'comment', 'like', 'search'] as $action) { Restrictions::moderation_action_rule($reputationActor, $action); }
$assert($pdo->reputationQueries === $beforeQueries + 1, 'One request computes reputation once across actions.');
\TinyCat\Http\Request::reset();
Restrictions::moderation_action_rule($reputationActor, 'post');
$assert($pdo->reputationQueries === $beforeQueries + 2, 'The next request refreshes reputation.');

// Failed work rolls back its quota, and exhaustion rolls back the domain write.
$pdo->exec('CREATE TABLE accepted_actions (id INTEGER)');
$quotaActor = ['id' => 90, 'role' => 'user', 'created_at' => date('Y-m-d H:i:s')];
try {
    \TinyCat\Database\Transactions::run(static function () use ($quotaActor): void {
        Restrictions::moderation_record_action($quotaActor, 'post');
        throw new RuntimeException('injected write failure');
    });
} catch (RuntimeException) {}
$assert(Restrictions::moderation_action_count($quotaActor, 'post') === 0, 'Failed writes do not consume success quotas.');
for ($i = 0; $i < 6; $i++) {
    try {
        \TinyCat\Database\Transactions::run(static function () use ($quotaActor): void {
            Connection::exec('INSERT INTO accepted_actions VALUES (1)');
            Restrictions::moderation_record_action($quotaActor, 'post');
        });
    } catch (\TinyCat\Http\HttpException $error) { $assert($error->status === 429, 'Exhaustion is a public 429.'); }
}
$assert((int) Connection::value('SELECT COUNT(*) FROM accepted_actions') === 5, 'Exhaustion rolls back the domain write.');
$assert(Restrictions::moderation_action_count($quotaActor, 'post') === 5, 'Only committed successes consume quota.');
$assert(!\TinyCat\Core\Security\Captcha::isActive(), 'Guest search regression runs without CAPTCHA.');
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
for ($i = 0; $i < \TinyCat\Social\Search\Policy::GUEST_MAX; $i++) {
    $_SESSION = [];
    $assert(\TinyCat\Social\Search\Guard::public_search_guard('nebula') === null, 'Guest budget accepts searches without CAPTCHA.');
}
$_SESSION = [];
$assert(\TinyCat\Social\Search\Guard::public_search_guard('nebula')['code'] === 'action_limited', 'Discarding session cannot reset guest search budget.');
\TinyCat\Social\Search\Guard::public_search_guest_unlock();
$assert(\TinyCat\Social\Search\Guard::public_search_guard('nebula')['code'] === 'action_limited', 'CAPTCHA unlock cannot bypass the server budget.');
$_SERVER['REMOTE_ADDR'] = '192.0.2.78';
$assert(\TinyCat\Social\Search\Guard::public_search_guard('nebula') === null, 'Independent IP retains its search budget.');

for ($i = 0; $i < \TinyCat\Social\Search\Policy::SUGGESTION_MAX; $i++) {
    $_SESSION = [];
    \TinyCat\Social\Search\Guard::suggestions();
}
try {
    $_SESSION = [];
    \TinyCat\Social\Search\Guard::suggestions();
    throw new RuntimeException('Autocomplete bypassed its database budget');
} catch (\TinyCat\Http\HttpException $error) { $assert($error->status === 429, 'Autocomplete survives discarded sessions.'); }
$assert(RateLimiter::rate_limit_ip_action_count('search_guest', 300) === 1, 'Autocomplete never consumes full searches.');
foreach (['admin'] as $role) {
    \TinyCat\Core\Identity\Authentication::reset(['id' => 99, 'role' => $role], true);
    $assert(\TinyCat\Social\Search\Guard::public_search_guard('nebula') === null, 'Verified privileged role bypasses search quota.');
    \TinyCat\Social\Search\Guard::suggestions();
    $assert(RateLimiter::subject_action_count('user:99', 'search_suggestions', 60) === 0, 'Privileged autocomplete consumes no quota.');
}
\TinyCat\Core\Identity\Authentication::reset(null, true);
\TinyCat\Core\Security\RateLimitPolicy::retainWindows('fixture.long-window', [100000]);
$assert(\TinyCat\Core\Security\RateLimitPolicy::retentionSeconds() === 200000, 'Retention follows declared owner windows instead of a core list of Social actions');
echo "PASS rate limiter isolation and cooldowns ({$checks} checks)\n";
