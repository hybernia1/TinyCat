<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

use TinyCat\Core\Identity\Administration;
use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Deletion;
use TinyCat\Core\Identity\Participant;
use TinyCat\Core\Identity\ValidationException;
use TinyCat\Database\Connection;

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/Core/Runtime/autoload.php';
TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(locale: static fn (): string => 'en'));
TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($root, [
])->catalog);
$databaseConfig = \tinycat_test_database_config();
$pdo = \tinycat_test_mysql_connect($databaseConfig);
Connection::set($pdo);
$pdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254) UNIQUE, email VARCHAR(254) UNIQUE, password TEXT, role VARCHAR(40), status VARCHAR(24), bio TEXT DEFAULT (''), locale TEXT DEFAULT ('en'), theme VARCHAR(12) DEFAULT ('system'), created_at DATETIME DEFAULT ('2026-11-11 00:00:00'))");
$pdo->exec('CREATE TABLE password_reset_tokens (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, token_hash TEXT, expires_at DATETIME, used_at TEXT)');
$hash = Authentication::authPassword('Admin-fixture-42');
$insert = $pdo->prepare("INSERT INTO users (id, username, email, password, role, status, bio, created_at) VALUES (?, ?, ?, ?, ?, 'active', 'Original bio', ?)");
foreach ([[1, 'Primary', 'primary@example.test', $hash, 'admin', '2026-01-01'], [2, 'Admin', 'admin@example.test', $hash, 'admin', '2026-02-01'], [3, 'Member', 'member@example.test', $hash, 'user', '2026-03-01'], [4, 'Bot', null, $hash, 'bot', '2026-04-01'], [5, 'Older', null, $hash, 'user', '2025-01-01']] as $data) { $insert->execute($data); }
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$row = static fn (int $id): array => Connection::find('users', ['id' => $id]) ?? throw new RuntimeException('Missing account fixture');
$actor = $row(2); $member = $row(3);
$denied = static function (callable $action, string $code = 'forbidden') use ($assert): void {
    try { $action(); } catch (DomainException $exception) { $assert($exception->getMessage() === $code, 'Administrative policy rejection has the expected reason'); return; }
    throw new RuntimeException('Administrative policy was bypassed');
};
$invalid = static function (callable $action, array $issues) use ($assert): void {
    try { $action(); } catch (ValidationException $exception) { $assert($exception->issues === $issues, 'Administrative field diagnostics remain exact'); return; }
    throw new RuntimeException('Invalid administrative input was accepted');
};
$input = ['username' => ' New.Member ', 'email' => ' NEW@EXAMPLE.TEST ', 'password' => 'Created-fixture-43', 'role' => 'user', 'status' => 'waiting', 'bio' => '<b>Hello</b>'];
$assert(Administration::primaryId() === 1 && Administration::isPrimary(1) && !Administration::isPrimary(0), 'Oldest administrator remains the protected identity');
$denied(static fn () => Administration::create($member, $input));
$denied(static fn () => Administration::create(['id' => 0, 'role' => 'admin'], $input));
$denied(static fn () => Administration::update($member, 3, ['role' => 'admin']));
$denied(static fn () => Administration::setActive($member, $member, false));
$denied(static fn () => Administration::delete($member, 3));
$assert((int) Connection::value('SELECT COUNT(*) FROM users') === 5 && $row(3) === $member, 'Unauthorized operations have no account effects');
$invalid(static fn () => Administration::create($actor, array_replace($input, ['role' => 'bot'])), ['role' => ['users.validation.role_invalid']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['status' => 'unknown'])), ['status' => ['users.validation.status_invalid']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['role' => ['admin']])), ['role' => ['users.validation.role_invalid']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['email' => ['invalid']])), ['email' => ['account.messages.email_invalid']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['password' => ['invalid']])), ['password' => ['users.validation.password_required']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['username' => '123'])), ['username' => ['users.messages.username_invalid']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['username' => 'MEMBER'])), ['username' => ['users.messages.username_taken']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['email' => 'bad'])), ['email' => ['account.messages.email_invalid']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['email' => 'MEMBER@EXAMPLE.TEST'])), ['email' => ['account.messages.email_taken']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['password' => ''])), ['password' => ['users.validation.password_required']]);
$invalid(static fn () => Administration::create($actor, array_replace($input, ['password' => 'short'])), ['password' => ['users.validation.password_min']]);
$unicodePassword = Administration::prepare($actor, array_replace($input, ['password' => 'éééé']));
$assert(password_verify('éééé', $unicodePassword['password']), 'Administrator creation accepts an eight-byte Unicode password');
$invalid(static fn () => Administration::create($actor, array_replace($input, ['password' => str_repeat('x', 1025)])), ['password' => ['users.validation.password_max']]);
$created = Administration::create($actor, $input + ['id' => 999, 'theme' => 'dark', 'locale' => 'cs', 'avatar_exists' => 1]);
$new = $row($created);
$assert($created !== 999 && $new['username'] === 'New.Member' && $new['email'] === 'new@example.test' && $new['bio'] === 'Hello', 'Creation normalizes permitted fields');
$assert($new['role'] === 'user' && $new['status'] === 'waiting' && $new['theme'] === 'system' && $new['locale'] === 'en', 'Administrative creation preserves defaults and ignores unrelated fields');
$assert($new['password'] !== $input['password'] && password_verify($input['password'], $new['password']), 'Administrative creation stores a hash');
$updated = Administration::update($actor, 3, ['role' => 'user', 'username' => 'Renamed', 'password' => '', 'theme' => 'dark']);
$assert($updated['username'] === 'Member' && $updated['email'] === $member['email'] && $updated['status'] === 'active' && $updated['password'] === $hash, 'Update keeps immutable username and omitted values');
$assert($updated['bio'] === '' && $updated['theme'] === 'system', 'Legacy bio default and field selection remain stable');
$pdo->exec("INSERT INTO password_reset_tokens (id, user_id, token_hash) VALUES (1, 3, 'old-admin-token')");
$reset = Administration::update($actor, 3, ['role' => 'user', 'password' => 'Admin-reset-44', 'status' => 'waiting', 'email' => '']);
$assert((int) Connection::value('SELECT COUNT(*) FROM password_reset_tokens') === 0, 'Administrative credential reset revokes issued reset tokens');
$assert($reset['email'] === null && $reset['status'] === 'waiting' && password_verify('Admin-reset-44', $reset['password']), 'Administrative reset does not require the owner password');
$denied(static fn () => Administration::update($actor, 4, ['role' => 'user']), 'user_not_found');
$protected = ['role' => ['users.messages.super_admin_protected'], 'status' => ['users.messages.super_admin_protected']];
$invalid(static fn () => Administration::update($actor, 1, ['role' => 'user', 'status' => 'active']), $protected);
$invalid(static fn () => Administration::update($actor, 1, ['role' => 'admin', 'status' => 'ban']), $protected);
$denied(static fn () => Administration::setActive($actor, $row(1), false), 'super_admin_protected');
$guardCallbacks = 0;
$participant = new Participant(prepareDelete: static function (array $user) use (&$guardCallbacks): Deletion { $guardCallbacks++; return new Deletion(); });
$denied(static fn () => Administration::delete($actor, 1, ['fixture' => $participant]), 'super_admin_protected');
$assert($guardCallbacks === 0 && $row(1)['role'] === 'admin' && $row(1)['status'] === 'active', 'Primary protection runs before deletion participants');
$preparedBeforePromotion = Administration::prepare($actor, ['role' => 'user', 'status' => 'ban'], $row(5));
// Retain an older repeatable-read snapshot while another connection promotes an older account.
$pdo->beginTransaction();
$assert((int) Connection::value("SELECT id FROM users WHERE role = 'admin' ORDER BY created_at, id LIMIT 1") === 1, 'Initial snapshot sees the existing primary');
$assert((int) Connection::value('SELECT COUNT(*) FROM users') > 0, 'Establish an ordinary consistent-read snapshot');
$contender = \tinycat_test_mysql_connect($databaseConfig);
$contender->exec("UPDATE users SET role = 'admin', status = 'active' WHERE id = 5");
$lockedPromotion = Connection::one('SELECT * FROM users WHERE id = ? FOR UPDATE', [5]);
try {
    $invalid(static fn () => Administration::persist($actor, $lockedPromotion, $preparedBeforePromotion), $protected);
    $denied(static fn () => Administration::setActive($actor, $lockedPromotion, false), 'super_admin_protected');
} finally { $pdo->rollBack(); }
$denied(static fn () => Administration::delete($actor, 5), 'super_admin_protected');
$assert(Administration::isPrimary(5) && !Administration::isPrimary(1), 'UI policy observes a competing promotion without a static cache');
$contender = null;
$assert(Administration::primaryId() === 5, 'Primary selection observes changed database state without stale mutation cache');
$pdo->beginTransaction();
try { $invalid(static fn () => Administration::persist($actor, $row(5), $preparedBeforePromotion), $protected); }
finally { $pdo->rollBack(); }
$assert($row(5)['role'] === 'admin' && $row(5)['status'] === 'active', 'Previously prepared changes cannot demote the new primary inside a file transaction');
$prepared = Administration::prepare($actor, ['role' => 'user', 'bio' => 'Prepared'], $row(3));
$invalid(static fn () => Administration::prepare($actor, ['role' => 'user', 'bio' => null], $row(3)), ['bio' => ['common.request_failed']]);
try { Administration::persist($actor, $row(3), $prepared); throw new RuntimeException('Missing transaction was accepted'); }
catch (LogicException) { $assert(true, 'Prepared persistence requires a caller-owned transaction'); }
$pdo->beginTransaction();
try { Administration::persist($actor, $row(3), $prepared + ['username' => 'Forged', 'id' => 999, 'avatar_exists' => 1]); $pdo->commit(); }
catch (Throwable $exception) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $exception; }
$assert($row(3)['username'] === 'Member' && $row(3)['bio'] === 'Prepared', 'Prepared persistence also whitelists writable fields');
$pdo->exec("CREATE TRIGGER fail_admin_write BEFORE UPDATE ON users FOR EACH ROW BEGIN IF NEW.id = 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected admin SQL failure'; END IF; END");
try { Administration::update($actor, 3, ['role' => 'admin', 'status' => 'ban']); throw new LogicException('SQL failure was ignored'); }
catch (PDOException) { $assert($row(3)['role'] === 'user' && $row(3)['bio'] === 'Prepared' && !$pdo->inTransaction(), 'Failed administrative write rolls back'); }
$pdo->exec('DROP TRIGGER fail_admin_write');
$activation = [];
$statusParticipant = new Participant(statusChanged: static function (array $user, bool $active) use (&$activation, $pdo): void { $activation[] = [$user['role'], $active, $pdo->inTransaction()]; });
Administration::setActive($actor, ['id' => 3, 'role' => 'bot'], false, ['fixture' => $statusParticipant]);
$assert($row(3)['status'] === 'ban' && $activation === [['user', false, true]], 'Activation uses locked account data and the same transaction for participants');
Administration::setActive($actor, $row(1), false);
$assert($row(1)['status'] === 'ban' && $row(5)['status'] === 'active', 'Protection follows the actual oldest administrator');
$deleted = Administration::delete($actor, $created, ['fixture' => $participant]);
$assert((int) $deleted['id'] === $created && Connection::find('users', ['id' => $created]) === null && $guardCallbacks === 1, 'Administrative deletion uses registered domain effects');
$assert(Administration::delete($actor, $created) === null, 'Repeated deletion remains harmless');
foreach (['TinyCat\\Core\\Settings\\Store', 'TinyCat\\Social\\Posts\\Api', 'TinyCat\\Social\\Bots\\Api', 'TinyCat\\Http\\Request', 'TinyCat\\Http\\Response', 'TinyCat\\Http\\Session'] as $class) { $assert(!class_exists($class, false), 'Administrative domain did not load ' . $class); }
$assert(array_column(Connection::all("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"), 'name') === ['password_reset_tokens', 'users'], 'Administrative services need only account storage');
TinyCat\Core\Modules\Resources::withCatalog(TinyCat\Core\Modules\Loader::load($root)->catalog, static function () use ($assert, $actor) : void {
$assert(array_keys(\TinyCat\Core\Identity\Views::statusLabels()) === Administration::statuses(), 'UI status labels match domain status policy');
});

echo "PASS standalone account administration, primary protection and transaction integration ({$checks} checks)\n";
