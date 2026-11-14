<?php
declare(strict_types=1);

use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Profile;
use TinyCat\Core\Identity\ValidationException;
use TinyCat\Database\Connection;

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/autoload.php';
TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(locale: static fn (): string => 'en'));
TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($root, [
])->catalog);
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Connection::set($pdo);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, email TEXT UNIQUE COLLATE NOCASE, password TEXT, role TEXT, status TEXT, bio TEXT, locale TEXT, theme TEXT, email_notifications INTEGER)');
$password = 'Profile-fixture-42';
$hash = Authentication::authPassword($password);
$insert = $pdo->prepare("INSERT INTO users VALUES (?, ?, ?, ?, ?, 'active', 'Original bio', 'en', 'light', 1)");
foreach ([[1, 'Admin', 'admin@example.test', $hash, 'admin'], [2, 'Member', 'member@example.test', $hash, 'user'], [3, 'Other', 'other@example.test', $hash, 'user']] as $row) { $insert->execute($row); }
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$row = static fn (int $id): array => Connection::find('users', ['id' => $id]) ?? throw new RuntimeException('Missing fixture account');
$invalid = static function (callable $write, array $expected) use ($assert): void {
    try { $write(); } catch (ValidationException $exception) { $assert($exception->issues === $expected, 'Field diagnostics remain exact'); return; }
    throw new RuntimeException('Invalid profile write was accepted');
};
$denied = static function (callable $write) use ($assert): void {
    try { $write(); } catch (DomainException $exception) { $assert($exception->getMessage() === 'forbidden', 'Unauthorized account write is denied'); return; }
    throw new RuntimeException('Unauthorized profile write was accepted');
};
$member = $row(2); $admin = $row(1); $other = $row(3);
$assert(Profile::canEdit($member, $member) && Profile::canEdit($member, $admin), 'Owner and administrator can edit profiles');
$assert(!Profile::canEdit($member, $other) && !Profile::canEdit($member, null) && !Profile::canEdit(null, $admin), 'Other and missing accounts cannot edit');
$assert(!Profile::canEdit($member, ['id' => 0, 'role' => 'admin']), 'An administrator role without an identity is insufficient');
$denied(static fn () => Profile::update($member, $other, ['bio' => 'Forbidden'], ['en']));
$denied(static fn () => Profile::email($member, [], ['email' => 'forged@example.test']));
$denied(static fn () => Profile::password($member, $admin, ['current_password' => $password, 'password' => 'New-fixture-43', 'password_confirm' => 'New-fixture-43']));
$assert($row(2) === $member, 'Denied writes preserve all account data');
$updated = Profile::update($member, $member, ['bio' => ' <b>Hello</b> world ', 'locale' => 'CS', 'theme' => ' DARK ', 'role' => 'admin', 'username' => 'Forged', 'status' => 'ban', 'password' => 'plaintext'], ['en', 'cs']);
$assert($updated['bio'] === 'Hello world' && $updated['locale'] === 'cs' && $updated['theme'] === 'dark', 'Profile values use shared normalization');
$assert($row(2)['role'] === 'user' && $row(2)['username'] === 'Member' && $row(2)['status'] === 'active' && $row(2)['password'] === $hash, 'Mass assignment cannot alter identity, role, status or password');
$assert(TinyCat\Support\Localization::locale() === 'en', 'Domain profile write does not switch request language');
$partial = Profile::update($row(2), $member, ['theme' => 'light'], ['en', 'cs']);
$assert($partial['bio'] === 'Hello world' && $partial['locale'] === 'cs' && $partial['email'] === 'member@example.test' && $partial['email_notifications'] === 1, 'Theme-only update preserves other fields and notification preference');
$nulls = Profile::update($row(2), $member, ['bio' => null, 'theme' => null, 'email' => null, 'email_notifications' => null], ['en', 'cs']);
$assert($nulls['bio'] === '' && $nulls['theme'] === 'system' && $nulls['email'] === 'member@example.test' && $nulls['email_notifications'] === 1, 'Explicit null fields preserve existing request semantics');
$invalid(static fn () => Profile::update($row(2), $member, ['locale' => null, 'email' => 'invalid'], ['en', 'cs']), ['locale' => ['settings.messages.invalid_language'], 'email' => ['account.messages.email_invalid']]);
$invalid(static fn () => Profile::update($row(2), $member, ['locale' => 'de'], ['en', 'cs']), ['locale' => ['settings.messages.invalid_language']]);
$invalid(static fn () => Profile::update($row(2), $member, ['email' => 'OTHER@EXAMPLE.TEST'], ['en', 'cs']), ['email' => ['account.messages.email_taken']]);
$assert($row(2) === $nulls, 'Invalid writes do not partially apply');
$long = Profile::update($row(2), $member, ['bio' => str_repeat('ž', 550)], ['en', 'cs']);
$assert(preg_match_all('/./u', $long['bio']) === 500, 'Bio limit preserves complete Unicode characters');
$adminEdit = Profile::update($row(2), $admin, ['bio' => 'Edited by admin'], ['en', 'cs']);
$assert($adminEdit['bio'] === 'Edited by admin' && $row(1) === $admin, 'Administrator edits target only the selected account');
$changedEmail = Profile::email($row(2), $member, ['email' => ' NEW@EXAMPLE.TEST ', 'email_notifications' => '0', 'role' => 'admin']);
$assert($changedEmail['email'] === 'new@example.test' && $changedEmail['email_notifications'] === 0 && $changedEmail['role'] === 'user', 'Email write normalizes and whitelists fields');
$kept = Profile::email($row(2), $member, ['email' => 'new@example.test']);
$assert($kept['email_notifications'] === 0, 'Omitted preference remains unchanged');
$enabled = Profile::email($row(2), $member, ['email' => 'new@example.test', 'email_notifications' => '1']);
$assert($enabled['email_notifications'] === 1, 'Notifications can be enabled with an address');
$removed = Profile::email($row(2), $member, ['email' => '', 'email_notifications' => '1']);
$assert($removed['email'] === null && $removed['email_notifications'] === 0, 'Removing an address always disables email notifications');
$empty = Profile::update($row(2), $member, ['email_notifications' => '1'], ['en', 'cs']);
$assert($empty['email_notifications'] === 0, 'Profile cannot enable email notifications without an address');
$invalid(static fn () => Profile::email($row(2), $member, ['email' => 'broken']), ['email' => ['account.messages.email_invalid']]);
$invalid(static fn () => Profile::email($row(2), $member, ['email' => 'other@example.test']), ['email' => ['account.messages.email_taken']]);
$invalid(static fn () => Profile::password($row(2), $member, ['current_password' => 'wrong', 'password' => 'short', 'password_confirm' => 'short']), ['current_password' => ['account.messages.current_password_invalid'], 'password' => ['account.messages.password_short']]);
$invalid(static fn () => Profile::password($row(2), $member, ['current_password' => str_repeat('x', 1025), 'password' => 'New-fixture-43', 'password_confirm' => 'New-fixture-43']), ['current_password' => ['account.messages.current_password_invalid']]);
$invalid(static fn () => Profile::password($row(2), $member, ['current_password' => $password, 'password' => 'New-fixture-43', 'password_confirm' => 'different']), ['password_confirm' => ['account.messages.password_mismatch']]);
$assert($row(2)['password'] === $hash, 'Invalid password input leaves stored credentials unchanged');
$beforePassword = $row(2);
Profile::password($beforePassword, $member, ['current_password' => $password, 'password' => 'New-fixture-43', 'password_confirm' => 'New-fixture-43']);
$newHash = $row(2)['password'];
$assert($newHash !== $hash && password_verify('New-fixture-43', $newHash), 'Password write stores a new hash');
$assert(array_diff_assoc($row(2), $beforePassword) === ['password' => $newHash], 'Password change cannot alter unrelated fields');
$invalid(static fn () => Profile::password($beforePassword, $member, ['current_password' => $password, 'password' => 'Stale-fixture-44', 'password_confirm' => 'Stale-fixture-44']), ['current_password' => ['account.messages.current_password_invalid']]);
$assert($row(2)['password'] === $newHash, 'Stale credentials cannot overwrite a concurrent password change');
$pdo->exec("CREATE TRIGGER reject_profile_write BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'injected profile SQL failure'); END");
try { Profile::update($row(2), $member, ['bio' => 'Failed SQL write'], ['en', 'cs']); throw new LogicException('SQL failure was ignored'); }
catch (PDOException) { $assert($row(2)['bio'] === 'Edited by admin', 'SQL failure leaves stored profile unchanged'); }
$pdo->exec('DROP TRIGGER reject_profile_write');
foreach (['TinyCat\\Social\\Profiles\\Profiles', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Social\\Posts\\Registration', 'TinyCat\\Social\\Automation\\Registration', 'TinyCat\\Core\\Notifications\\ServiceProvider', 'TinyCat\\Http\\Request', 'TinyCat\\Http\\Response', 'TinyCat\\Http\\Session'] as $class) {
    $assert(!class_exists($class, false), 'Domain profile writes did not load ' . $class);
}
$assert(array_column(Connection::all("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name') === ['users'], 'Profile management needs only account storage');
TinyCat\Core\Modules\Resources::withCatalog(TinyCat\Core\Modules\Loader::load($root)->catalog, static function () use ($assert, $member, $admin) : void {
$assert(TinyCat\Social\Profiles\Profiles::user_can_edit_profile($member, $admin) === Profile::canEdit($member, $admin), 'Legacy profile permission delegates to Accounts');
$assert(TinyCat\Social\Profiles\Profiles::theme_normalize('DARK') === Profile::theme('DARK') && TinyCat\Social\Profiles\Profiles::user_theme(['theme' => 'unknown']) === 'system', 'Legacy theme helpers retain canonical normalization');
});

echo "PASS standalone account profile, permissions, field selection and credential races ({$checks} checks)\n";
