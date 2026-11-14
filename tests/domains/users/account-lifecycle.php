<?php
declare(strict_types=1);

// File effects must run in a copied application, never against local uploads.
if (($argv[1] ?? '') !== '--isolated') {
    $workspace = sys_get_temp_dir() . '/tinycat-accounts-' . bin2hex(random_bytes(10));
    $sourceRoot = dirname(__DIR__, 3);
    $copy = static function (string $source, string $target) use (&$copy): void {
        if (is_file($source)) { copy($source, $target); return; }
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { $copy($source . '/' . $entry, $target . '/' . $entry); }
        }
    };
    try {
        foreach (['App', 'tests/fixtures/modules/notes'] as $relative) { $copy($sourceRoot . '/' . $relative, $workspace . '/' . $relative); }
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($workspace), $exitCode);
    } finally {
        $resolved = realpath($workspace);
        $temporary = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-accounts-')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
            }
            rmdir($resolved);
        }
    }
    exit($exitCode);
}

use TinyCat\Core\Identity\Deletion;
use TinyCat\Core\Identity\Lifecycle;
use TinyCat\Core\Identity\Participant;
use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Resources;
use TinyCat\Database\Connection;

define('TINYCAT', true);
$root = $argv[2];
require $root . '/App/autoload.php';
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$modules = Loader::load($root);
$participants = TinyCat\Core\Identity\Participants::fromModules($modules);
$assert(array_keys($participants) === ['tinycat.accounts', 'tinycat.social'], 'Selected owners expose deterministic account participants in dependency order');
foreach ([Connection::class, TinyCat\Core\Settings\Store::class, TinyCat\Http\Session::class] as $class) {
    $assert(!class_exists($class, false), 'Participant definitions do not load ' . $class);
}
$assert(session_status() === PHP_SESSION_NONE, 'Definitions do not start a session');
$selection = new TinyCat\Core\Modules\Catalog([$modules->catalog->get('tinycat.accounts'), $modules->catalog->get('tinycat.security')]);
$assert(array_keys(TinyCat\Core\Identity\Participants::fromModules($modules, $selection)) === ['tinycat.accounts'], 'Unselected social owners do not contribute');
$assert(TinyCat\Core\Identity\Participants::fromModules($modules, new TinyCat\Core\Modules\Catalog([])) === [], 'Empty assembly has no account integrations');

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Connection::set($pdo);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT, role TEXT, created_at TEXT DEFAULT '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO users (id, status, role) VALUES (1, 'active', 'user'), (2, 'active', 'bot'), (3, 'active', 'user')");
Lifecycle::setActive(['id' => 1, 'role' => 'user'], false);
$assert(Connection::value('SELECT status FROM users WHERE id = 1') === 'ban', 'Accounts works with only its table');
Lifecycle::setActive(['id' => 1], true);
$assert(Connection::value('SELECT status FROM users WHERE id = 1') === 'active', 'Account can be activated without social SQL');
$assert(class_exists(TinyCat\Social\Profiles\Accounts::class), 'Load the compatibility facade while its owning module is selected');
Resources::withCatalog($selection, static function () use ($assert): void {
    TinyCat\Social\Profiles\Accounts::setActive(['id' => 1, 'role' => 'bot'], false);
    $assert(Connection::value('SELECT status FROM users WHERE id = 1') === 'ban', 'Legacy facade respects selected owners, even for a bot role');
});
$events = [];
$observer = new Participant(prepareDelete: static function (array $user) use (&$events, $pdo, $assert): Deletion {
    $assert($pdo->inTransaction(), 'Preparation runs inside the transaction');
    $assert((int) Connection::value('SELECT COUNT(*) FROM users WHERE id = ?', [$user['id']]) === 1, 'Preparation sees the account');
    $events[] = 'prepare';
    return new Deletion(
        afterDelete: static function () use (&$events, $pdo, $assert): void {
            $assert($pdo->inTransaction(), 'Database cleanup shares the transaction');
            $assert((int) Connection::value('SELECT COUNT(*) FROM users WHERE id = 1') === 0, 'Cleanup sees the deleted row');
            $events[] = 'deleted';
        },
        afterCommit: static function () use (&$events, $pdo, $assert): void {
            $assert(!$pdo->inTransaction(), 'File cleanup runs only after commit');
            $events[] = 'committed';
        },
    );
});
$assert((int) Lifecycle::delete(1, ['fixture.observer' => $observer])['id'] === 1, 'Deletion returns the previous account');
$assert($events === ['prepare', 'deleted', 'committed'], 'Lifecycle stages have stable order');
$assert(Lifecycle::delete(1, ['fixture.observer' => $observer]) === null && Lifecycle::delete(0) === null, 'Missing and invalid accounts have no effects');
$assert($events === ['prepare', 'deleted', 'committed'], 'Repeated deletion does not rerun cleanup');
$failed = new Participant(prepareDelete: static fn (array $user): Deletion => new Deletion(
    afterDelete: static function (): void { throw new RuntimeException('injected deletion failure'); },
    afterCommit: static function () use (&$events): void { $events[] = 'incorrect file deletion'; },
));
try { Lifecycle::delete(3, ['fixture.failure' => $failed]); throw new LogicException('Failure was ignored'); }
catch (RuntimeException $exception) { $assert($exception->getMessage() === 'injected deletion failure', 'Deletion failure is propagated'); }
$assert((int) Connection::value('SELECT COUNT(*) FROM users WHERE id = 3') === 1, 'Database failure restores the account');
$assert(!in_array('incorrect file deletion', $events, true), 'Database rollback preserves files');
$pdo->beginTransaction();
try { Lifecycle::delete(3); throw new RuntimeException('Nested deletion was accepted'); }
catch (LogicException) { $assert($pdo->inTransaction(), 'Nested deletion is rejected without touching caller transaction'); }
$pdo->rollBack();

$pdo->exec('CREATE TABLE bot_sources (id INTEGER PRIMARY KEY, bot_user_id INTEGER, enabled INTEGER)');
$pdo->exec('INSERT INTO bot_sources VALUES (1, 2, 1), (2, 3, 1)');
Lifecycle::setActive(['id' => 2, 'role' => 'bot'], false, ['tinycat.social' => $participants['tinycat.social']]);
$assert((int) Connection::value('SELECT enabled FROM bot_sources WHERE id = 1') === 0, 'Bots owns source deactivation');
$assert((int) Connection::value('SELECT enabled FROM bot_sources WHERE id = 2') === 1, 'Other owners are unchanged');
Lifecycle::setActive(['id' => 2, 'role' => 'bot'], true, ['tinycat.social' => $participants['tinycat.social']]);
$assert((int) Connection::value('SELECT enabled FROM bot_sources WHERE id = 1') === 0, 'Activation does not implicitly reenable sources');
$statusFailure = new Participant(statusChanged: static function (array $user, bool $active): void { throw new RuntimeException('injected activation failure'); });
try { Lifecycle::setActive(['id' => 3], false, ['fixture.failure' => $statusFailure]); throw new LogicException('Failure was ignored'); }
catch (RuntimeException $exception) { $assert($exception->getMessage() === 'injected activation failure', 'Status participant failure is propagated'); }
$assert(Connection::value('SELECT status FROM users WHERE id = 3') === 'active', 'Status participant failure rolls account change back');

$pdo->exec("CREATE TABLE social_posts (id INTEGER PRIMARY KEY, author_id INTEGER REFERENCES users(id) ON DELETE CASCADE, kind TEXT NOT NULL DEFAULT 'post')");
TinyCat\Social\Content\InteractionSchema::install($pdo);
TinyCat\Core\Notifications\Schema::install($pdo);
$pdo->exec('CREATE TABLE social_terms (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE social_links (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE social_post_tags (content_id INTEGER REFERENCES social_posts(id) ON DELETE CASCADE, term_id INTEGER)');
$pdo->exec('CREATE TABLE social_post_links (content_id INTEGER REFERENCES social_posts(id) ON DELETE CASCADE, link_id INTEGER)');
$pdo->exec('CREATE TABLE social_post_images (content_id INTEGER REFERENCES social_posts(id) ON DELETE CASCADE, path TEXT)');
$pdo->exec('INSERT INTO social_posts(id, author_id) VALUES (10, 2), (11, 3)');
$pdo->exec('INSERT INTO social_terms VALUES (1), (2), (3)');
$pdo->exec('INSERT INTO social_links VALUES (1), (2), (3)');
$pdo->exec('INSERT INTO social_post_tags VALUES (10, 1), (10, 2), (11, 2)');
$pdo->exec('INSERT INTO social_post_links VALUES (10, 1), (10, 2), (11, 2)');
$imagePath = '2026/11/' . str_repeat('a', 32) . '.webp';
mkdir($root . '/uploads/status-images/2026/11', 0700, true);
mkdir($root . '/uploads/avatars', 0700, true);
$imageFile = $root . '/uploads/status-images/' . $imagePath;
$avatarFile = $root . '/uploads/avatars/2.webp';
file_put_contents($imageFile, 'fixture image');
file_put_contents($avatarFile, 'fixture avatar');
$pdo->prepare('INSERT INTO social_post_images VALUES (10, ?)')->execute([$imagePath]);
$pdo->exec("CREATE TRIGGER reject_account_delete BEFORE DELETE ON users WHEN OLD.id = 2 BEGIN SELECT RAISE(ABORT, 'injected SQL failure'); END");
try { Lifecycle::delete(2, $participants); throw new LogicException('SQL failure was ignored'); }
catch (PDOException) { $assert(true, 'Real SQL failure aborts deletion'); }
$assert(is_file($avatarFile) && is_file($imageFile), 'SQL failure preserves actual avatar and publishing files');
$assert((int) Connection::value('SELECT COUNT(*) FROM social_post_images WHERE content_id = 10') === 1, 'SQL failure preserves publishing metadata');
$pdo->exec('DROP TRIGGER reject_account_delete');
Lifecycle::delete(2, $participants);
$assert(!is_file($avatarFile) && !is_file($imageFile), 'Successful commit removes actual avatar and publishing files through their owners');
$assert((int) Connection::value('SELECT COUNT(*) FROM social_posts WHERE id = 10') === 0, 'Account cascade removes owned publishing rows');
$assert(array_column(Connection::all('SELECT id FROM social_terms ORDER BY id'), 'id') === [2, 3], 'Publishing removes only collected orphan terms');
$assert(array_column(Connection::all('SELECT id FROM social_links ORDER BY id'), 'id') === [2, 3], 'Publishing preserves shared and unrelated links');
$assert((int) Connection::value('SELECT COUNT(*) FROM social_posts WHERE id = 11') === 1, 'Other author content survives');

$manifestSelection = require $root . '/App/modules.php';
$manifestSelection['demo.notes'] = 'tests/fixtures/modules/notes/module.json';
$withManifest = Loader::fromSelection($root, $manifestSelection);
$manifestParticipants = TinyCat\Core\Identity\Participants::fromModules($withManifest);
$assert(isset($manifestParticipants['demo.notes']), 'Selected manifest contribution provider exposes the Accounts extension');
$pdo->exec('CREATE TABLE pilot_notes (id INTEGER PRIMARY KEY, created_by INTEGER)');
$pdo->exec('INSERT INTO pilot_notes VALUES (1, 3), (2, 999)');
Lifecycle::delete(3, ['demo.notes' => $manifestParticipants['demo.notes']]);
$assert(array_column(Connection::all('SELECT id FROM pilot_notes'), 'id') === [2], 'Manifest module owns its account-related cleanup without a central domain list');
$assert(!isset(TinyCat\Core\Identity\Participants::fromModules($modules)['demo.notes']), 'Removing the manifest removes its lifecycle callbacks');
echo "PASS account lifecycle ownership, minimal schema, rollback and selected integrations ({$checks} checks)\n";
