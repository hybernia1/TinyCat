<?php
declare(strict_types=1);

$sourceRoot = dirname(__DIR__, 3);
if (($argv[1] ?? '') !== '--isolated') {
    $workspace = sys_get_temp_dir() . '/tinycat-publishing-' . bin2hex(random_bytes(10));
    $excluded = ['App/Modules/Blog', 'App/Core/Support/compiled', 'assets/build'];
    $copy = static function (string $relative) use (&$copy, $sourceRoot, $workspace, $excluded): void {
        if (in_array($relative, $excluded, true)) { return; }
        if (preg_match('~^assets/modules/(?:status|users|bots|notifications|moderation|settings|site|search)$~', $relative)) { return; }
        $source = $sourceRoot . '/' . $relative; $target = $workspace . '/' . $relative;
        if (is_file($source)) { copy($source, $target); return; }
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (scandir($source) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($relative . '/' . $entry); } }
    };
    try {
        foreach (['App', 'assets', 'lang', 'tools'] as $directory) { $copy($directory); }
        file_put_contents($workspace . '/App/modules.php', "<?php\nreturn ['tinycat.social' => 'App/Modules/Social/module.json'];\n");
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($workspace), $exitCode);
    } finally {
        $resolved = realpath($workspace); $temporary = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-publishing-')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
            }
            rmdir($resolved);
        }
    }
    exit($exitCode);
}

use TinyCat\Database\Connection;
use TinyCat\Social\Content\Archive;
use TinyCat\Social\Content\Integration;
use TinyCat\Social\Content\PostCreation;
use TinyCat\Social\Content\Posts;
use TinyCat\Social\Content\Queries;

define('TINYCAT', true);
$workspace = $argv[2];
require $workspace . '/App/autoload.php';
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$core = TinyCat\Core\Modules\Loader::fromSelection($workspace, []);
TinyCat\Core\Modules\Resources::configure($core->catalog);
$coreDb = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
TinyCat\Database\ModuleSchemas::install($coreDb, TinyCat\Core\Modules\Distribution::definitions($core, false));
$coreTables = $coreDb->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$assert($coreTables === ['ip_action_limits', 'module_installations', 'module_migrations', 'notifications', 'password_reset_tokens', 'scheduler_runs', 'settings', 'users'], 'Core creates exactly eight framework tables without a domain module');
$assert(!$core->catalog->has('tinycat.publishing') && !$core->catalog->has('tinycat.social') && !$core->catalog->has('tinycat.blog'), 'Core has no implicit publication capability or domain owner');
foreach (['Status', 'Users', 'Bots', 'Notifications', 'Moderation', 'Settings', 'Site', 'Search'] as $domain) { $assert(!is_dir($workspace . '/App/Modules/' . $domain), 'No physical product sources: ' . $domain); }
$modules = TinyCat\Core\Modules\Loader::load($workspace);
$modules->initialize(); Integration::configure();
$api = TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(new TinyCat\Core\Runtime\Configuration(['datetime' => ['timezone' => 'UTC'], 'publishing' => ['base_url' => 'https://publication.example']]), locale: static fn (): string => 'en'), $modules);
$assert(array_keys(TinyCat\Core\Identity\Participants::current()) === ['tinycat.accounts', 'tinycat.social'], 'Only selected owners contribute account lifecycle');
$assert(TinyCat\Core\Modules\Registrations::current()->owner('maintenance', 'orphan_terms') === 'tinycat.social', 'Existing maintenance ID has the publishing owner');
$routes = (new ReflectionProperty(TinyCat\Http\Router::class, 'routes'))->getValue();
$assert(str_contains(json_encode(array_column($routes, 'path')), '/statuses'), 'Services do not silently activate social routes');
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON'); Connection::set($pdo);
TinyCat\Core\Identity\Schema::install($pdo); TinyCat\Core\Security\Schema::install($pdo); TinyCat\Social\Content\Schema::install($pdo); TinyCat\Social\Content\InteractionSchema::install($pdo); TinyCat\Social\Content\Schema::install($pdo); TinyCat\Social\Content\InteractionSchema::install($pdo);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$assert(count($tables) === 13 && in_array('social_posts', $tables, true) && !in_array('content', $tables, true), 'Fresh schema owns shared comments and reactions without following or bot tables');
$pdo->exec("INSERT INTO users(id, username, role, status) VALUES (1, 'Writer', 'user', 'active'), (2, 'Disabled', 'user', 'ban'), (3, 'Admin', 'admin', 'active')");
$writer = Connection::find('users', ['id' => 1]); $admin = Connection::find('users', ['id' => 3]);
$draft = PostCreation::fromText(" <b>Hello</b> @reader #Mixed_CASE\r\nhttps://example.test/article?utm_source=x ");
$assert($draft->rejection === null && $draft->tags === ['mixed-case'] && str_contains($draft->body, '@reader'), 'Generic preparation normalizes text and tags without social mention lookup');
$assert(count($draft->links) === 1 && !str_contains($draft->links[0]['normalized_url'], 'utm_'), 'Canonical links use shared parsing');
$id = PostCreation::create(1, $draft, $draft->links, publishedAt: '2000-01-01 12:00:00');
$assert(Queries::find($id)['body'] === $draft->body && Posts::canEdit(Posts::find($id), $writer) && Posts::canEdit(Posts::find($id), $admin), 'Public projection and owner/admin policy work without social tables');
$assert(!Posts::canEdit(Posts::find($id), ['id' => 9, 'role' => 'user']) && !Posts::canEdit(null, $admin), 'Unrelated actors and missing posts are not editable');
$second = PostCreation::create(1, PostCreation::fromText('Second #mixed_case'), [], publishedAt: '2000-01-01 12:00:00');
$future = PostCreation::create(1, PostCreation::fromText('Future'), [], publishedAt: '2999-01-01 12:00:00');
$hidden = PostCreation::create(1, PostCreation::fromText('Hidden'), [], publishedAt: '2000-01-01 12:00:00');
Connection::update('social_posts', ['status' => 'hidden'], ['id' => $hidden]);
$disabled = PostCreation::create(2, PostCreation::fromText('Disabled author'), [], publishedAt: '2000-01-01 12:00:00');
$assert(Queries::find($future) === null && Queries::find($hidden) === null && Queries::find($disabled) === null, 'Visibility excludes future, hidden and disabled-author content');
$page = Queries::page(1); $next = Queries::page(1, $page[0]['published_at'], (int) $page[0]['id']);
$assert((int) $page[0]['id'] === $second && (int) $next[0]['id'] === $id, 'Equal timestamps use a stable descending ID cursor');
$assert(count(Queries::page(tag: 'mixed_case')) === 2 && count(Queries::page(media: 'links')) === 1 && count(Queries::page(media: 'text')) === 1, 'Tag and media selection do not depend on social projections');
$assert(Archive::cursor('invalid', $id) === ['', []] && Archive::type('unknown') === '', 'Malformed cursors and unknown media filters keep existing semantics');
$before = (int) Connection::value('SELECT COUNT(*) FROM social_posts');
$pdo->exec("CREATE TRIGGER reject_link BEFORE INSERT ON social_post_links BEGIN SELECT RAISE(ABORT, 'injected relation failure'); END");
try { $bad = PostCreation::fromText('Rollback #unique https://rollback.test'); PostCreation::create(1, $bad, $bad->links); $assert(false, 'Creation must fail'); }
catch (PDOException) { $assert((int) Connection::value('SELECT COUNT(*) FROM social_posts') === $before && !Connection::value("SELECT id FROM social_terms WHERE name = 'unique'"), 'Failure rolls back content and taxonomy together'); }
$pdo->exec('DROP TRIGGER reject_link');
$pdo->beginTransaction(); $outer = PostCreation::create(1, PostCreation::fromText('Outer transaction'), []); $assert($pdo->inTransaction(), 'Creation preserves the caller transaction'); $pdo->rollBack();
$assert(Posts::find($outer) === null, 'Caller rollback removes publication');
$update = PostCreation::fromText('Updated #replacement'); Posts::update($id, $update, [], null, false, null);
$assert(Posts::find($id)['body'] === $update->body && count(Queries::page(tag: 'replacement')) === 1, 'Updates share the accepted preparation and relation writer');
$pdo->exec("CREATE TRIGGER reject_update BEFORE UPDATE ON social_posts BEGIN SELECT RAISE(ABORT, 'injected write failure'); END");
try { Posts::update($id, PostCreation::fromText('Rejected #change'), [], null, false, null); $assert(false, 'Update must fail'); }
catch (PDOException) { $assert(Posts::find($id)['body'] === $update->body, 'Failed update preserves original social_posts'); }
$pdo->exec('DROP TRIGGER reject_update');
Integration::configure(blocked: static fn (string $text): string => str_contains($text, 'blocked.test') ? 'blocked.test' : '', mentions: static fn (string $text): string => str_replace('@reader', '@1', $text));
$blocked = PostCreation::fromText('https://blocked.test');
$assert($blocked->rejection['code'] === 'blocked_url' && PostCreation::fromText('@reader')->body === '@1', 'Product policies are explicit lazy inputs');
$assert(TinyCat\Support\Localization::t($blocked->rejection['message_key'], ['host' => 'blocked.test']) !== $blocked->rejection['message_key'], 'Policy rejection text does not require Moderation sources');
try { PostCreation::create(1, $blocked, []); $assert(false, 'Rejected draft cannot be persisted'); } catch (InvalidArgumentException) { $assert(true, 'Shared writer rejects policy failures'); }
Integration::configure();
$assert(PostCreation::fromText('#' . str_repeat('x', 33))->rejection['code'] === 'status_tag_too_long', 'Tag bounds use the same publishing validation');
$assert(TinyCat\Support\Localization::t('account.messages.status_tag_too_long', ['max' => '32']) !== 'account.messages.status_tag_too_long', 'Publishing owns its validation catalog');
mkdir($workspace . '/uploads/status-images/2000/01', 0700, true); $imagePath = '2000/01/' . str_repeat('a', 32) . '.webp';
$file = $workspace . '/uploads/status-images/' . $imagePath; file_put_contents($file, 'prepared-image');
$imageId = PostCreation::create(1, PostCreation::fromText('Image'), [], ['path' => $imagePath, 'width' => 1, 'height' => 1, 'bytes' => 14], '2000-01-01 12:00:00');
$pdo->exec("CREATE TRIGGER reject_delete BEFORE DELETE ON social_posts BEGIN SELECT RAISE(ABORT, 'injected deletion failure'); END");
try { Posts::delete($imageId); $assert(false, 'Deletion must fail'); }
catch (PDOException) { $assert(Posts::find($imageId) !== null && is_file($file) && Connection::value('SELECT content_id FROM social_post_images WHERE content_id = ' . $imageId), 'Failed deletion preserves relations and the physical image'); }
$pdo->exec('DROP TRIGGER reject_delete');
$pdo->beginTransaction();
try { Posts::delete($imageId); $assert(false, 'Outer transaction must defer files'); } catch (LogicException) { $assert(is_file($file), 'Outer transaction cannot prematurely remove media'); }
$pdo->rollBack(); Posts::delete($imageId); clearstatcache(true, $file);
$assert(Posts::find($imageId) === null && !is_file($file), 'Successful deletion removes the file after commit');
try { Posts::delete($id, static function () use ($pdo): void { $pdo->exec("UPDATE social_posts SET body = 'side effect'"); throw new RuntimeException('domain callback failed'); }); $assert(false, 'Callback must fail'); }
catch (RuntimeException) { $assert(Posts::find($id)['body'] === $update->body, 'Related owner failure rolls back its effects and the publication'); }
$deletion = TinyCat\Social\Content\ServiceProvider::accountLifecycle()->prepareDelete;

$pdo->beginTransaction(); $cleanup = $deletion($writer); $pdo->exec('DELETE FROM users WHERE id = 1'); ($cleanup->afterDelete)(); $pdo->commit(); ($cleanup->afterCommit)();
$assert((int) Connection::value('SELECT COUNT(*) FROM social_posts WHERE author_id = 1') === 0 && (int) Connection::value('SELECT COUNT(*) FROM social_post_tags') === 0, 'Account deletion uses only publishing-owned cleanup');
$compile = require $workspace . '/tools/assets/compile-assets.php'; $built = $compile();
$assert(isset($built['assets/build/manifest.json']) && count(TinyCat\Support\TranslationSources::packages()) === 12, 'Selected services and Accounts build all locale assets without product sources');
$feed = TinyCat\Core\Feeds\FeedParser::parse('<rss version="2.0"><channel><item><title>Standalone</title><link>https://example.com/post</link><description>Feed body</description><category>News</category></item></channel></rss>');
$assert(count($feed) === 1 && $feed[0]['title'] === 'Standalone', 'Publishing parses feeds without Bots sources');
$rendered = TinyCat\Social\Content\FeedImport::renderPost(['name' => 'Feed'], $feed[0]);
$assert(str_contains($rendered, 'Standalone') && str_contains($rendered, '#news'), 'Shared import prepares feed text and category tags');
$assert(TinyCat\Social\Content\FeedImport::prepareImage([], $feed[0]) === null, 'Text-only imports do not load bot or social media adapters');
foreach (get_declared_classes() as $class) { $assert(!preg_match('/^TinyCat\\\\(?:Status|Users|Bots|Notifications|Moderation|Settings|Site|Search)\\\\/', $class), 'No product class loaded: ' . $class); }
echo "PASS Social-owned content schema, preparation, reads, writes, rollback and build ({$checks} checks)\n";
