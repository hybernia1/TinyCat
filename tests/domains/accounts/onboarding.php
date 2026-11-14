<?php
declare(strict_types=1);

use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Email;
use TinyCat\Core\Identity\Passwords;
use TinyCat\Core\Identity\Recovery;
use TinyCat\Core\Identity\Registration;
use TinyCat\Core\Identity\Usernames;
use TinyCat\Database\Connection;

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/autoload.php';
$context = new TinyCat\Core\Runtime\Context(
    new TinyCat\Core\Runtime\Configuration([]), locale: static fn (): string => 'en',
);
TinyCat\Core\Runtime\Application::boot($context);
TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($root, [
])->catalog);
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Connection::set($pdo);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE COLLATE NOCASE, email TEXT UNIQUE COLLATE NOCASE, password TEXT, role TEXT, status TEXT, locale TEXT, theme TEXT, bio TEXT)");
$pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, token_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT)');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$password = 'Registration-fixture-42';
$input = ['username' => '  New.Member  ', 'email' => '  MEMBER@EXAMPLE.TEST  ', 'password' => $password, 'password_confirm' => $password, 'platform_terms' => '1'];
$valid = Registration::input($input);
$assert($valid['username'] === 'New.Member' && $valid['email'] === 'member@example.test' && $valid['errors'] === [], 'Canonical registration normalizes identifiers');
$assert(Registration::input($input + ['role' => 'admin']) === $valid, 'Transport-only authority fields do not enter registration data');
$anonymous = Registration::input(array_replace($input, ['username' => 'Anonymous', 'email' => '']));
$assert($anonymous['errors'] === [], 'Email remains optional');
foreach ([['username' => '123'], ['email' => 'invalid'], ['password' => 'short'], ['password_confirm' => 'different'], ['platform_terms' => '0']] as $invalid) {
    $key = array_key_first($invalid);
    $assert(isset(Registration::input(array_replace($input, $invalid))['field_errors'][$key]), 'Invalid registration field retains canonical errors: ' . $key);
}
$assert(isset(Registration::input(array_replace($input, ['password' => str_repeat('x', 1025)]))['field_errors']['password']), 'Registration enforces the password byte limit');
$assert(Passwords::fieldErrors(str_repeat('x', 1024), str_repeat('x', 1024)) === [], 'Maximum password length remains accepted');
$assert(Email::maxLength() === 254 && !Email::valid(str_repeat('x', 255) . '@example.test'), 'Email length and format policy is shared');
$assert(Usernames::valid('Žaneta.Novák') && !Usernames::valid('123'), 'Account identifier grammar preserves Unicode and numeric reservations');
$created = Registration::create($valid + ['role' => 'admin', 'status' => 'active', 'theme' => 'dark'], false, 'cs');
$userId = (int) $created['user_id'];
$user = Connection::find('users', ['id' => $userId]);
$assert($created['status'] === 'waiting' && !$created['approved'] && $user['role'] === 'user', 'Approval and role cannot be overridden by registration input');
$assert($user['locale'] === 'cs' && $user['theme'] === 'system' && $user['bio'] === '', 'Account defaults and supplied locale retain their behavior');
$assert($user['password'] !== $password && password_verify($password, $user['password']), 'Registration stores a password hash');
$assert(!Authentication::authAttempt(['username' => 'New.Member', 'password' => $password]), 'Waiting registration cannot authenticate');
$duplicate = Registration::input($input);
$assert(isset($duplicate['field_errors']['username'], $duplicate['field_errors']['email']), 'Existing identifiers are rejected before insertion');
$assert(!Email::taken('MEMBER@EXAMPLE.TEST', $userId) && !Usernames::taken('new.member', $userId), 'Identifier checks can exclude the current account');
$active = Registration::create($anonymous, true, 'en');
$activeId = (int) $active['user_id'];
$assert($active['approved'] && $active['status'] === 'active' && Connection::value('SELECT email FROM users WHERE id = ?', [$activeId]) === null, 'Auto-approved pseudonymous accounts keep a null email');
$deliveries = [];
$deliver = static function (int $id, string $token) use (&$deliveries): void { $deliveries[] = ['id' => $id, 'token' => $token]; };
foreach (['invalid', 'missing@example.test', 'member@example.test'] as $email) { Recovery::request($email, $deliver); }
$assert($deliveries === [] && (int) Connection::value('SELECT COUNT(*) FROM password_reset_tokens') === 0, 'Unknown, invalid and inactive addresses have no token or delivery');
Connection::update('users', ['status' => 'active'], ['id' => $userId]);
Recovery::request(' MEMBER@EXAMPLE.TEST ', $deliver);
$token = $deliveries[0]['token'];
$row = Connection::one('SELECT * FROM password_reset_tokens');
$assert($deliveries[0]['id'] === $userId && strlen($token) === 64 && ctype_xdigit($token), 'Trusted delivery receives the generated account token');
$assert($row['token_hash'] === hash('sha256', $token) && $row['token_hash'] !== $token, 'Only the token hash is stored');
$expiry = new DateTimeImmutable($row['expires_at'], TinyCat\Support\Dates::timezone());
$assert(abs($expiry->getTimestamp() - time() - 3600) < 10, 'Recovery token retains the one-hour lifetime');
Recovery::request('member@example.test', $deliver);
$replacement = $deliveries[1]['token'];
$assert($replacement !== $token && (int) Connection::value('SELECT COUNT(*) FROM password_reset_tokens') === 1, 'New request replaces the previous token');
$assert(isset(Recovery::confirm($token, $password, $password)['token']), 'Superseded token cannot be consumed');
$assert(isset(Recovery::confirm($replacement, 'short', 'short')['password']), 'Recovery uses the same password policy as registration');
$assert(isset(Recovery::confirm($replacement, $password, 'different')['password_confirm']), 'Recovery preserves confirmation errors');
$assert(Connection::value('SELECT used_at FROM password_reset_tokens') === null, 'Validation failure leaves the token available');
$pdo->exec("CREATE TRIGGER reject_password_write BEFORE UPDATE OF password ON users BEGIN SELECT RAISE(ABORT, 'injected write failure'); END");
try { Recovery::confirm($replacement, 'Changed-password-43', 'Changed-password-43'); throw new LogicException('SQL failure was ignored'); }
catch (PDOException) { $assert(true, 'Password write failure is propagated'); }
$assert(Connection::value('SELECT used_at FROM password_reset_tokens') === null && password_verify($password, Connection::value('SELECT password FROM users WHERE id = ?', [$userId])), 'Password write failure rolls token consumption back');
$pdo->exec('DROP TRIGGER reject_password_write');
$assert(Recovery::confirm($replacement, 'Changed-password-43', 'Changed-password-43') === [], 'Valid token changes the password');
$assert(password_verify('Changed-password-43', Connection::value('SELECT password FROM users WHERE id = ?', [$userId])), 'Recovery persists the new password hash');
$assert(isset(Recovery::confirm($replacement, 'Other-password-44', 'Other-password-44')['token']), 'Consumed token cannot be replayed');
Recovery::replaceToken($userId, hash('sha256', 'expired-fixture'), TinyCat\Support\Dates::dateDb('-1 minute'));
$assert(isset(Recovery::confirm('expired-fixture', $password, $password)['token']), 'Expired token is rejected');
Recovery::replaceToken($userId, hash('sha256', 'banned-fixture'), TinyCat\Support\Dates::dateDb('+1 hour'));
Connection::update('users', ['status' => 'ban'], ['id' => $userId]);
$assert(isset(Recovery::confirm('banned-fixture', $password, $password)['token']), 'Ban between request and confirmation prevents recovery');
try { Recovery::consumeToken($userId, hash('sha256', 'banned-fixture'), Authentication::authPassword($password), TinyCat\Support\Dates::dateDb()); throw new LogicException('Inactive write was accepted'); }
catch (RuntimeException $exception) { $assert(str_contains($exception->getMessage(), 'user changed'), 'Transactional consumption refuses a changed account'); }
$assert(Connection::value('SELECT used_at FROM password_reset_tokens') === null, 'Changed-account failure restores the unused token');
$assert(!Recovery::consumeToken(0, '', '', '') && !Recovery::consumeToken($userId, 'missing', 'hash', TinyCat\Support\Dates::dateDb()), 'Invalid or missing token consumption has no write');
$assert(!Authentication::authAttempt(['username' => 'Anonymous', 'password' => str_repeat('x', 1025)]), 'Canonical authentication enforces the shared password bound');
$datePolicy = new TinyCat\Core\Runtime\Context(dates: static fn (string $key, mixed $default): mixed => $key === 'datetime.timezone' ? 'Pacific/Auckland' : $default);
TinyCat\Support\Dates::configure($datePolicy->dateOption(...));
$assert(TinyCat\Support\Dates::dateDb(new DateTimeImmutable('2026-11-11 12:00:00 UTC')) === '2026-11-12 01:00:00', 'Explicit date policy controls timezone without Settings');
$snapshot = new TinyCat\Core\Runtime\Context(new TinyCat\Core\Runtime\Configuration(['datetime.timezone' => 'UTC', 'datetime.database' => 'Y-m-d H:i:s']));
TinyCat\Support\Dates::configure($snapshot->dateOption(...));
$assert(TinyCat\Support\Dates::dateDb(new DateTimeImmutable('2026-11-11 12:00:00 UTC')) === '2026-11-11 12:00:00', 'Technical date policy defaults to the configuration snapshot');
TinyCat\Support\Dates::configure($context->dateOption(...));
foreach (['TinyCat\\Social\\Profiles\\Profiles', 'TinyCat\\Core\\Identity\\Login', 'TinyCat\\Social\\Profiles\\Accounts', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Core\\Mail\\Templates', 'TinyCat\\Social\\Presentation\\Presentation', 'TinyCat\\Social\\Posts\\Registration', 'TinyCat\\Social\\Automation\\Registration'] as $class) {
    $assert(!class_exists($class, false), 'Standalone onboarding did not load ' . $class);
}
$assert(array_column(Connection::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name <> 'sqlite_sequence' ORDER BY name"), 'name') === ['password_reset_tokens', 'users'], 'Only account and reset-token tables are needed');
TinyCat\Core\Modules\Resources::withCatalog(TinyCat\Core\Modules\Loader::load($root)->catalog, static function () use ($assert, $userId) : void {
$assert(TinyCat\Social\Profiles\Usernames::username_normalize(' Name ') === Usernames::normalize(' Name '), 'Legacy username normalization delegates to Accounts');
$assert(TinyCat\Social\Profiles\Profiles::user_email_valid('name@example.test') === Email::valid('name@example.test'), 'Legacy email helpers delegate to Accounts');
$assert(TinyCat\Core\Identity\Login::auth_password_validation_field_errors('short', 'short') === Passwords::fieldErrors('short', 'short'), 'Legacy password validation delegates to Accounts');
TinyCat\Core\Identity\Recovery::replaceToken($userId, hash('sha256', 'compatibility'), TinyCat\Support\Dates::dateDb('+1 hour'));
$assert(Connection::value('SELECT token_hash FROM password_reset_tokens') === hash('sha256', 'compatibility'), 'Legacy recovery writes through the canonical owner');
});

echo "PASS standalone registration, recovery, token rollback and compatibility ({$checks} checks)\n";
