<?php
declare(strict_types=1);

$sourceRoot = dirname(__DIR__, 3);
if (!in_array($argv[1] ?? '', ['--isolated', '--combined'], true)) {
    $workspace = sys_get_temp_dir() . '/tinycat-blog-' . bin2hex(random_bytes(10));
    $excluded = ['App/Modules/Social', 'App/Core/Support/compiled', 'assets/build'];
    $copy = static function (string $relative) use (&$copy, $sourceRoot, $workspace, &$excluded): void {
        if (in_array($relative, $excluded, true) || ($excluded !== [] && preg_match('~^assets/modules/(?:status|users|bots|notifications|moderation|settings|site|search)$~', $relative))) { return; }
        $source = $sourceRoot . '/' . $relative; $target = $workspace . '/' . $relative;
        if (is_file($source)) { copy($source, $target); return; }
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (scandir($source) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($relative . '/' . $entry); } }
    };
    try {
        foreach (['App', 'assets', 'lang', 'tools'] as $directory) { $copy($directory); }
        copy($sourceRoot . '/index.php', $workspace . '/index.php');
        file_put_contents($workspace . '/App/modules.php', "<?php\nreturn require __DIR__ . '/Modules/Blog/selection.php';\n");
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($workspace), $exitCode);
        if ($exitCode === 0) {
            $excluded = [];
            foreach (['App', 'assets'] as $directory) { $copy($directory); }
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --combined ' . escapeshellarg($workspace), $exitCode);
        }
    } finally {
        $resolved = realpath($workspace); $temporary = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-blog-')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
            }
            rmdir($resolved);
        }
    }
    exit($exitCode);
}
$workspace = $argv[2];
define('TINYCAT', true);
require $workspace . '/App/autoload.php';
if (($argv[1] ?? '') === '--combined') { require $sourceRoot . '/tests/fixtures/blog/coexistence.php'; exit(0); }
use TinyCat\Blog\Articles;
use TinyCat\Blog\Installation;
use TinyCat\Core\Runtime\Configuration;
use TinyCat\Database\Connection;
use TinyCat\Database\ModuleSchemas;

$checks = 0;
$completed = false;
register_shutdown_function(static function () use (&$completed): void { if (!$completed) { fwrite(STDERR, "Blog assertions did not reach completion.\n"); exit(1); } });
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$pdo = new PDO('sqlite:' . $workspace . '/blog.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Connection::set($pdo);
Installation::install($pdo);
Installation::boot(new Configuration(['cache' => ['directory' => $workspace . '/cache'], 'i18n' => ['locale' => 'cs']]));
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$assert(count($tables) === 21, 'Fresh Blog creates the mandatory framework, article specialization and migration metadata');
foreach (['followers', 'bots', 'reports'] as $table) { $assert(!in_array($table, $tables, true), 'No compulsory product table: ' . $table); }
$pdo->exec("INSERT INTO users(id, username, password, role, status) VALUES (1, 'Editor', 'fixture', 'admin', 'active'), (2, 'Reader', 'fixture', 'user', 'active')");
$admin = Connection::find('users', ['id' => 1]);
require $sourceRoot . '/tests/fixtures/blog/feed-cases.php';
$body = str_repeat('Long article text. ', 250) . '<script>alert(1)</script>';
$id = Articles::save(['title' => '<b>First article</b>', 'body' => $body], $admin);
$assert(Articles::find($id)['title'] === 'First article' && strlen(Articles::find($id)['body']) > 2000 && !str_contains(Articles::find($id)['body'], '<script>'), 'Article owns a sanitized long body and title');
$assert(Connection::find('blog_articles', ['id' => $id])['excerpt'] === Articles::excerpt(Articles::find($id)['body']), 'Publication preview preserves the shared 5.x text contract');
$hidden = Articles::save(['title' => 'Private draft', 'body' => 'Secret text', 'status' => 'hidden'], $admin);
$assert(Articles::find($hidden) === null && Articles::find($hidden, false) !== null, 'Hidden articles are editable but not public');
foreach ([[], ['id' => 2, 'role' => 'user', 'status' => 'active'], ['id' => 1, 'role' => 'admin', 'status' => 'ban']] as $actor) {
    try { Articles::save(['title' => 'Forbidden', 'body' => 'Text'], $actor); $assert(false, 'Invalid actor accepted'); }
    catch (DomainException $error) { $assert($error->getCode() === 403, 'Service rejects inactive and unauthorized writers'); }
}
foreach ([['title' => '', 'body' => 'text'], ['title' => 'x', 'body' => ''], ['title' => str_repeat('a', 201), 'body' => 'x'], ['title' => 'x', 'body' => str_repeat('x', 60001)], ['title' => 'x', 'body' => 'x', 'status' => 'unknown']] as $input) {
    try { Articles::save($input, $admin); $assert(false, 'Invalid input accepted'); } catch (InvalidArgumentException) { $assert(true, 'Article bounds enforced before writes'); }
}
TinyCat\Core\Settings\Store::setSetting('blog.blocked_urls', 'blocked.test, domain:invalid', 'string', 'security');
$assert(TinyCat\Blog\Settings::blockedHost('See https://sub.blocked.test/x') === 'blocked.test', 'Content URL policy works without any Social files');
try { Articles::save(['title' => 'Rejected', 'body' => 'https://blocked.test/x'], $admin); $assert(false, 'Core blocking bypassed'); } catch (InvalidArgumentException) { $assert(true, 'Blog enforces the persistent content URL policy'); }
TinyCat\Core\Settings\Store::setSetting('blog.blocked_urls', '', 'string', 'security');
TinyCat\Core\Text\Policies::provide('fixture.social', 'post', new TinyCat\Core\Text\TextPolicy(normalize: static fn (string $text): string => str_replace('@name', '@42', $text)));
$assert(TinyCat\Core\Text\Policies::policy('article')->normalize === null && (TinyCat\Core\Text\Policies::policy('post')->normalize)('Hello @name') === 'Hello @42', 'Named rendering hooks remain isolated from article storage');
TinyCat\Core\Text\Policies::forget('post');
TinyCat\Core\Settings\Store::setSetting('blog.blocked_urls', 'blocked.test', 'string', 'blog');
try { Articles::save(['title' => 'Beyond preview', 'body' => str_repeat('x', 2500) . ' https://blocked.test'], $admin); $assert(false, 'Full article policy bypassed'); }
catch (InvalidArgumentException) { $assert(true, 'Blog policy checks the full article beyond the preview'); }
TinyCat\Core\Settings\Store::setSetting('blog.blocked_urls', '', 'string', 'blog');
$before = Connection::value('SELECT COUNT(*) FROM blog_articles');
$pdo->exec("CREATE TRIGGER reject_article BEFORE INSERT ON blog_articles BEGIN SELECT RAISE(ABORT, 'injected article failure'); END");
try { Articles::save(['title' => 'Rollback', 'body' => 'Body #rollback'], $admin); $assert(false, 'Failure expected'); }
catch (PDOException) { $assert(Connection::value('SELECT COUNT(*) FROM blog_articles') === $before && !Connection::value("SELECT id FROM blog_terms WHERE name = 'rollback'"), 'Failed specialization rolls back publication and tags'); }
$pdo->exec('DROP TRIGGER reject_article');
Articles::save(['title' => 'Changed', 'body' => 'Changed text'], $admin, $id);
$assert(Articles::find($id)['title'] === 'Changed' && Connection::find('blog_articles', ['id' => $id])['body'] === 'Changed text', 'Edit shares the publication transaction');
$pdo->exec("CREATE TRIGGER reject_article_update BEFORE UPDATE ON blog_articles BEGIN SELECT RAISE(ABORT, 'injected update failure'); END");
try { Articles::save(['title' => 'Failed', 'body' => 'Failed text'], $admin, $id); $assert(false, 'Failure expected'); }
catch (PDOException) { $assert(Connection::find('blog_articles', ['id' => $id])['body'] === 'Changed text', 'Failed specialization update rolls back the preview'); }
$pdo->exec('DROP TRIGGER reject_article_update');
for ($i = 0; $i < 23; $i++) { Articles::save(['title' => 'Article ' . $i, 'body' => 'Body'], $admin); }
$page = Articles::page(); $next = Articles::page((int) $page[19]['id'], $page[19]['published_at']);
$assert(count($page) === 21 && count(array_intersect(array_column(array_slice($page, 0, 20), 'id'), array_column($next, 'id'))) === 0, 'Public pages use a stable exclusive cursor');
$future = Articles::save(['title' => 'Future', 'body' => 'Text'], $admin);
Connection::update('blog_articles', ['published_at' => '2999-01-01 00:00:00'], ['id' => $future]);
$assert(Articles::find($future) === null, 'Future publications are not exposed');
Connection::update('users', ['status' => 'ban'], ['id' => 1]);
$assert(Articles::page() === [], 'Inactive authors are not public');
Connection::update('users', ['status' => 'active'], ['id' => 1]);
$state = ModuleSchemas::state($pdo, 'tinycat.blog');
Installation::upgrade($pdo); Installation::upgrade($pdo);
$assert(ModuleSchemas::state($pdo, 'tinycat.blog') === $state && Articles::find($id)['title'] === 'Changed', 'Repeated upgrade verifies history and preserves existing articles');
try { Installation::install($pdo); $assert(false, 'Fresh installer adopted existing data'); } catch (RuntimeException) { $assert(Articles::find($id) !== null, 'Fresh install refuses an existing database'); }
$pdo->exec("UPDATE module_migrations SET checksum = 'tampered' WHERE module_id = 'tinycat.blog' AND version = 1");
try { Installation::upgrade($pdo); $assert(false, 'Checksum mismatch accepted'); } catch (RuntimeException) { $assert(true, 'Upgrade rejects modified history'); }
$pdo->exec("UPDATE module_migrations SET checksum = '" . ModuleSchemas::checksum($workspace . '/App/Modules/Blog/migrations/001-articles.php') . "' WHERE module_id = 'tinycat.blog' AND version = 1");
Articles::delete($hidden, $admin);
$assert(Connection::find('blog_articles', ['id' => $hidden]) === null && Connection::find('blog_articles', ['id' => $hidden]) === null, 'Publication deletion cascades article data');
TinyCat\Core\Modules\Providers::assertDistributable(Installation::modules()->catalog);
$assert(TinyCat\Core\Modules\Distribution::modular(TinyCat\Core\Modules\Distribution::describe($workspace, 'tinycat.blog', require $workspace . '/App/Modules/Blog/selection.php')), 'Blog distribution declares its own managed schema history');
$recovery = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$recovery->exec('PRAGMA foreign_keys = ON'); TinyCat\Core\Identity\Schema::install($recovery);
foreach (['001-initial.php', '002-kinds.php'] as $migration) { (require $sourceRoot . '/App/Modules/Social/migrations/publishing/' . $migration)($recovery); }
(require $sourceRoot . '/App/Modules/Social/migrations/publishing/interactions/001-initial.php')($recovery);
$schema = TinyCat\Blog\Module::schema();
$interrupted = new TinyCat\Core\Modules\ModuleSchema($schema->version, $schema->tables, static function (PDO $pdo): void { throw new RuntimeException('Interrupted before DDL'); }, $schema->migrations);
try { ModuleSchemas::install($recovery, ['tinycat.blog' => $interrupted]); $assert(false, 'Injected installation should fail'); } catch (RuntimeException) { $assert(ModuleSchemas::state($recovery, 'tinycat.blog') === null, 'SQLite failed DDL transaction rolls back its state'); }
// MySQL can retain this interrupted state after implicit DDL commits; exercise its recovery migration on SQLite too.
$recovery->exec("INSERT INTO module_installations(module_id, version, status) VALUES ('tinycat.blog', 0, 'failed')");
ModuleSchemas::upgrade($recovery, ['tinycat.blog' => $schema]);
$assert(ModuleSchemas::ready($recovery, ['tinycat.blog' => $schema]), 'Actual Blog migration upgrades an interrupted version zero install');
// Adopt the published Scheduler history without loading its adapter.
$historyDb = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$oldHistory = $workspace . '/App/Core/Tasks/migrations/001-initial.php';
$oldSchema = new TinyCat\Core\Modules\ModuleSchema(1, ['scheduler_runs'], static function (PDO $db) use ($oldHistory): void { (require $oldHistory)($db); }, [new TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.scheduler.initial', $oldHistory)]);
ModuleSchemas::install($historyDb, ['tinycat.scheduler' => $oldSchema]);
$historyDb->exec("INSERT INTO scheduler_runs(id, task, mode, status, started_at, options_json) VALUES (42, 'retained', 'cli', 'completed', '2026-01-01 00:00:00', '{}')");
$coreHistory = ['tinycat.support' => TinyCat\Core\Tasks\Schema::schema($workspace)];
ModuleSchemas::synchronize($historyDb, $coreHistory);
$assert(ModuleSchemas::ready($historyDb, $coreHistory) && (int) $historyDb->query('SELECT id FROM scheduler_runs')->fetchColumn() === 42, 'Core adoption preserves existing execution IDs and published Scheduler history');
$historyDb->exec("UPDATE module_migrations SET checksum = 'tampered' WHERE module_id = 'tinycat.scheduler'");
try { ModuleSchemas::pending($historyDb, $coreHistory); $assert(false, 'Corrupt legacy migration accepted'); } catch (RuntimeException) { $assert(true, 'Core deployment rejects corrupt legacy history after adapter removal'); }
$historyDb->exec("UPDATE module_migrations SET checksum = '" . TinyCat\Core\Tasks\Schema::LEGACY_CHECKSUM . "' WHERE module_id = 'tinycat.scheduler'");
(require $sourceRoot . '/migrations/20261112_003_core_task_history.php')($historyDb);
$assert((int) $historyDb->query('SELECT id FROM scheduler_runs')->fetchColumn() === 42 && ModuleSchemas::ready($historyDb, $coreHistory), 'Legacy global upgrade and standalone synchronization share the same immutable history baseline');
$compile = require $workspace . '/tools/assets/compile-assets.php'; $files = $compile();
$assert(count(TinyCat\Support\TranslationSources::packages()) === 12, 'Blog selection builds all twelve locales');
foreach (TinyCat\Support\ClientAssets::scopes() as $scope) {
    $sources = implode("\n", TinyCat\Support\AssetGraph::order(TinyCat\Support\ClientAssets::entries($scope)));
    $assert(!preg_match('~/modules/(?:status|users|bots|notifications|settings|site|moderation)/~', $sources), 'No social frontend import: ' . $scope);
}
foreach ($files as $path => $content) { $target = $workspace . '/' . $path; if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); } file_put_contents($target, $content); }
file_put_contents($workspace . '/config.php', "<?php\nreturn " . var_export(['database' => ['dsn' => 'sqlite:' . $workspace . '/blog.sqlite'], 'cache' => ['directory' => $workspace . '/cache'], 'i18n' => ['locale' => 'cs']], true) . ";\n");
mkdir($workspace . '/sessions', 0700);
$server = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) { throw new RuntimeException('Cannot allocate Blog fixture port.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $workspace . '/sessions', '-S', $address, $sourceRoot . '/tests/fixtures/blog/module-worker.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace . '/server.log', 'a'], 2 => ['file', $workspace . '/server.log', 'a']], $pipes, $workspace, array_merge(getenv(), ['TINYCAT_BLOG_WORKSPACE' => $workspace]));
    if (!is_resource($server)) { throw new RuntimeException('Cannot start Blog HTTP fixture.'); }
    fclose($pipes[0]); $cookies = []; $csrf = '';
    $request = static function (string $path, string $method = 'GET', ?array $payload = null, string $actor = '', bool $withCsrf = true, bool $multipart = false) use ($address, &$cookies, &$csrf): array {
        $handle = curl_init('http://' . $address . $path); $headers = []; $responseHeaders = [];
        if ($actor !== '') { $headers[] = 'Authorization: Bearer ' . $actor; }
        if ($withCsrf && $csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
        if ($cookies !== []) { $headers[] = 'Cookie: ' . implode('; ', $cookies); }
        if ($payload !== null) { if (!$multipart) { $headers[] = 'Content-Type: application/json'; } curl_setopt($handle, CURLOPT_POSTFIELDS, $multipart ? $payload : json_encode($payload)); }
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$cookies, &$responseHeaders): int {
                if (preg_match('/^Set-Cookie:\s*([^=]+=[^;]*)/i', $header, $match)) { $cookies[explode('=', $match[1], 2)[0]] = $match[1]; }
                if (str_contains($header, ':')) { [$name, $value] = explode(':', $header, 2); $responseHeaders[strtolower($name)] = trim($value); }
                return strlen($header);
            }]);
        $body = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        return [$status, is_string($body) ? (json_decode($body, true) ?? []) : [], (string) $body, $responseHeaders];
    };
    for ($attempt = 0; $attempt < 50; $attempt++) { [$status, , $html] = $request('/'); if ($status === 200) { break; } usleep(100_000); }
    $assert($status === 200 && str_contains($html, 'lang="cs"') && str_contains($html, 'Starší články'), 'Real root entry starts the Czech blog without Site');
    [, , , $headers] = $request('/');
    $assert(($headers['x-frame-options'] ?? '') === 'SAMEORIGIN' && str_contains($headers['content-security-policy'] ?? '', "object-src 'none'"), 'Blog uses the shared HTTP security headers without loading Settings');
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $match); $csrf = $match[1] ?? '';
    preg_match_all('~(?:src|href)="(/assets/build/[^\"]+)"~', $html, $urls);
    $assert($csrf !== '' && $urls[1] !== [], 'Blog HTML supplies CSRF and compiled assets');
    foreach (['', 'member'] as $actor) {
        [$status] = $request('/api/article-sources/creation', actor: $actor);
        $assert(in_array($status, [401, 403], true), 'Source editors require an administrator');
    }
    [$status, $resource] = $request('/api/article-sources/creation', actor: 'admin');
    $assert($status === 200 && $resource['data']['item']['publication_status'] === 'hidden' && count($resource['data']['authors']) === 1, 'Source creation loads canonical current author choices on demand');
    foreach (['', 'member'] as $actor) {
        [$status] = $request('/api/modules/packages/creation', actor: $actor);
        $assert(in_array($status, [401, 403], true), 'Module package editor requires an administrator');
    }
    [$status] = $request('/api/modules/packages', 'POST', [], 'admin', false);
    $assert($status === 403, 'Module package uploads require CSRF');
    [$status, $invalidPackage] = $request('/api/modules/packages', 'POST', [], 'admin');
    $assert($status === 422 && $invalidPackage['code'] === 'module_package_invalid', 'An upload cannot be replaced by a client-supplied server path');
    $sourceInput = ['name' => 'HTTP feed', 'feed_url' => 'https://93.184.216.34/http-feed.xml', 'author_id' => 1, 'interval_minutes' => 60, 'enabled' => false];
    [$status] = $request('/api/article-sources', 'POST', $sourceInput, 'admin', false);
    $assert($status === 403, 'Source writes require CSRF');
    [$status, $createdSource] = $request('/api/article-sources', 'POST', $sourceInput, 'admin');
    $sourceId = (int) ($createdSource['data']['item']['id'] ?? 0);
    $assert($status === 201 && $sourceId > 0 && !$createdSource['data']['item']['enabled'], 'Authorized source writes preserve disabled state');
    [$status] = $request('/api/article-sources', 'POST', array_replace($sourceInput, ['feed_url' => 'https://93.184.216.34:443/http-feed.xml/']), 'admin');
    $assert($status === 422, 'Equivalent feed URLs cannot create duplicate sources');
    [$status] = $request('/api/article-sources/' . $sourceId, 'PUT', array_replace($sourceInput, ['author_id' => 2]), 'admin');
    $assert($status === 422, 'A source cannot publish under an ordinary member identity');
    [$status, $preview] = $request('/api/article-sources/preview', 'POST', ['document' => '<rss><channel><item><guid>preview</guid><title>Preview article</title><description>Preview body</description></item></channel></rss>'], 'admin');
    $assert($status === 201 && $preview['data']['items'][0]['body'] === 'Preview body' && (int) $pdo->query('SELECT COUNT(*) FROM blog_feed_history')->fetchColumn() === 0, 'HTTP preview uses article preparation without creating successful GUID history');
    [$status] = $request('/api/article-sources/' . $sourceId, 'DELETE', actor: 'admin');
    $assert($status === 200 && \TinyCat\Blog\FeedSources::find($sourceId) === null, 'Source deletion leaves existing publications intact');
    foreach (array_unique($urls[1]) as $url) { [$status] = $request(html_entity_decode($url)); $assert($status === 200, 'Compiled asset delivered: ' . $url); }
    [$status, $data] = $request('/api/search/query?q=Article');
    $group = $data['data']['collections'][0] ?? [];
    $assert($status === 200 && ($group['id'] ?? '') === 'articles' && count($group['items'] ?? []) === 5 && str_starts_with($group['next_url'] ?? '', '/search/all?'), 'Blog-only search contributes bounded canonical results and a cursor: ' . $status . ' ' . json_encode($data));
    $assert(!in_array($hidden, array_column($group['items'], 'id'), true) && !in_array($future, array_column($group['items'], 'id'), true), 'Search excludes hidden and future articles');
    parse_str((string) parse_url($group['next_url'], PHP_URL_QUERY), $continuation);
    [$status, $data] = $request('/api/search/query?' . http_build_query($continuation));
    $nextGroup = $data['data']['collections'][0] ?? [];
    $assert($status === 200 && count($group['items']) + count($nextGroup['items']) === 23 && array_intersect(array_column($group['items'], 'id'), array_column($nextGroup['items'], 'id')) === [], 'Tied-time article search continues without omissions or duplicates');
    [$status, $data] = $request('/api/search/query?q=Text');
    $assert($status === 200 && array_column($data['data']['collections'][0]['items'], 'id') === [$id], 'Matching hidden and future article bodies are excluded from search');
    Connection::update('users', ['status' => 'ban'], ['id' => 1]);
    [$status, $data] = $request('/api/search/query?q=Article');
    $assert($status === 200 && $data['data']['collections'][0]['items'] === [], 'Search excludes publications of an inactive author');
    Connection::update('users', ['status' => 'active'], ['id' => 1]);
    [$status, $data] = $request('/api/search?q=Article');
    $assert($status === 200 && array_column($data['data']['collections'], 'id') === ['articles'] && count($data['data']['articles']) === 5, 'Suggestions discover only active sources without the Search module');
    [$status, , $searchHtml] = $request('/search/all?q=Article');
    $assert($status === 200 && str_contains($searchHtml, '/articles/') && str_contains($searchHtml, 'noindex'), 'Generic search renders in the core shell');
    [$status] = $request('/api/search/query?q=Article&cursor[cursor_at][]=bad');
    $assert($status === 400, 'Nested search cursors fail before owner SQL');
    [$status] = $request('/api/search/query?q=Article&collection=content');
    $assert($status === 404, 'Inactive search collections are absent');
    [$status, , $xml] = $request('/sitemap.xml');
    $assert($status === 200 && str_contains($xml, 'sitemap-articles-1.xml') && !str_contains($xml, 'sitemap-status'), 'Blog owns its sitemap without Site');
    [$status, , $xml] = $request('/sitemap-articles-1.xml');
    $assert($status === 200 && str_contains($xml, '/articles/' . $id . '</loc>') && !str_contains($xml, '/articles/' . $hidden . '</loc>') && !str_contains($xml, '/articles/' . $future . '</loc>'), 'Sitemap uses the public article visibility policy');
    [$status, , $robots] = $request('/robots.txt');
    $assert($status === 200 && str_contains($robots, 'Sitemap:') && str_contains($robots, 'Disallow: /admin'), 'Core crawler routes work without Site');
    [$status, $data] = $request('/api/articles/' . $id);
    $assert($status === 200 && ($data['data']['title'] ?? '') === 'Changed' && !isset($data['data']['password']), 'Public JSON returns canonical article data');
    [$status, , $html] = $request('/articles/' . $id);
    $assert($status === 200 && !str_contains($html, 'noindex') && !str_contains($html, 'href="/admin/articles/'), 'Public article is indexable and offers no unauthorized edit action');
    [$status, , $localized] = $request('/pl/articles/' . $id . '?draft=secret');
    $assert($status === 200 && str_contains($localized, 'lang="pl"') && str_contains($localized, 'href="/cs/articles/' . $id . '"'), 'Language selection retains the article without copying private query parameters');
    $assert(str_contains($localized, 'property="og:type" content="article"') && str_contains($localized, '"@type":"Article"') && !str_contains($localized, 'data-public-sidebar'), 'Blog owns article metadata within the independent shared document');
    [$status, , $localized] = $request('/pl/blog?before=8&at=2026-01-01&draft=secret');
    $assert($status === 200 && str_contains($localized, '/cs/blog?before=8&amp;at=2026-01-01') && !str_contains($localized, '/cs/blog?draft'), 'Archive language links preserve only declared cursor parameters');
    [$status, , $privacy] = $request('/pl/privacy');
    $assert($status === 200 && str_contains($privacy, 'lang="pl"') && !str_contains($privacy, 'privacy-limit-table'), 'Core privacy is translated and independent of social quotas');
    [$status, , $llms, $headers] = $request('/llms-full.txt');
    $assert($status === 200 && str_starts_with($headers['content-type'] ?? '', 'text/plain') && !str_contains($llms, '## Social'), 'Core LLM document has no inactive Social contribution');
    [$status, , $missing] = $request('/missing-stage-one');
    $assert($status === 404 && str_contains($missing, 'noindex') && str_contains(strtolower($missing), '<!doctype html>'), 'Missing pages use the shared noindex error document');
    [$status, , $missing, $headers] = $request('/assets/missing-stage-one.css');
    $assert($status === 404 && !str_contains(strtolower($missing), '<!doctype') && str_starts_with($headers['content-type'] ?? '', 'text/plain'), 'Missing assets receive a raw response instead of an HTML document');
    [$status] = $request('/api/moderation/blocked-urls', actor: 'admin');
    $assert($status === 404, 'Social moderation API is absent from Blog-only installation');
    [$status] = $request('/api/site/cache-resets', 'POST', []);
    $assert($status === 401, 'Core diagnostics reset remains private without Social');
    [$status] = $request('/api/site/cache-resets', 'POST', [], 'admin', false);
    $assert($status === 403, 'Core diagnostics mutation requires CSRF without Social');
    [$status, , $dashboard] = $request('/admin', actor: 'admin');
    $assert($status === 200 && str_contains($dashboard, 'id="dashboard-cache"') && !str_contains($dashboard, 'recent-comments'), 'Core administration hosts diagnostics without social tables');
    [$status, , $fallback] = $request('/fixture-core-maintenance');
    $assert($status === 503 && str_contains($fallback, 'noindex') && !str_contains($fallback, '/assets/build/'), 'Maintenance fallback renders without a database or external assets');
    [$status, , $fallback] = $request('/fixture-core-maintenance', 'HEAD');
    $assert($status === 503 && $fallback === '', 'Database-free fallback preserves HEAD semantics');
    [$status, $data] = $request('/api/fixture-unready');
    $assert($status === 503 && ($data['code'] ?? '') === 'database_not_ready', 'Unavailable database retains the shared JSON envelope without Social');
    [$status, , , $headers] = $request('/fixture-unready');
    $assert($status === 302 && ($headers['location'] ?? '') === '/install', 'Unavailable database directs HTML requests to the core installer');
    [$status, , $rss, $headers] = $request('/blog/feed');
    $feed = simplexml_load_string($rss);
    $assert($status === 200 && str_starts_with($headers['content-type'] ?? '', 'application/rss+xml') && $feed !== false && count($feed->channel->item) === 20 && !str_contains($rss, 'Private draft') && !str_contains($rss, '>Future<'), 'Blog supplies its own visible bounded RSS through the shared serializer');
    [$status, $embed] = $request('/oembed?url=' . rawurlencode('http://' . $address . '/articles/' . $id));
    $assert($status === 200 && ($embed['type'] ?? '') === 'rich' && ($embed['title'] ?? '') === 'Changed' && str_contains($embed['html'] ?? '', '/articles/' . $id), 'Generic oEmbed discovers articles without Social');
    [$status, , $xml] = $request('/oembed?format=xml&url=' . rawurlencode('http://' . $address . '/articles/' . $id));
    $assert($status === 200 && simplexml_load_string($xml) !== false, 'Article oEmbed supports the same XML transport');
    [$status] = $request('/oembed?url=' . rawurlencode('https://foreign.test/articles/' . $id)); $assert($status === 404, 'Shared oEmbed refuses foreign origins before owner lookup');
    [$status] = $request('/oembed?url=' . rawurlencode('http://' . $address . '/articles/' . $future)); $assert($status === 404, 'Future articles have no public embed');
    [$status, , $html] = $request('/articles/' . $id, 'HEAD'); $assert($status === 200 && $html === '', 'Root entry preserves HEAD semantics');
    [$status] = $request('/api/articles/' . $future); $assert($status === 404, 'Future article is not exposed over HTTP');
    [$status] = $request('/api/articles', 'POST', ['title' => 'Forbidden', 'body' => 'text']); $assert($status === 401, 'Article API requires identity');
    [$status] = $request('/api/articles', 'POST', ['title' => 'Forbidden', 'body' => 'text'], 'member'); $assert($status === 403, 'Reader cannot publish');
    [$status] = $request('/api/articles', 'POST', ['title' => 'Forbidden', 'body' => 'text'], 'admin', false); $assert($status === 403, 'Article API requires CSRF');
    [$status, $data] = $request('/api/articles', 'POST', ['title' => 'HTTP article', 'body' => 'HTTP body'], 'admin');
    $httpId = (int) ($data['data']['id'] ?? 0); $assert($status === 201 && $httpId > 0, 'Admin publishes through real API');
    // The same framework service must work with no Social source files or tables beyond shared interactions.
    [$status, $data] = $request('/api/articles/' . $id . '/discussion');
    $assert($status === 200 && $data['data']['items'] === [] && !$data['data']['capabilities']['comment'], 'Anonymous discussion is readable but cannot mutate');
    [$status] = $request('/api/articles/' . $id . '/comments', 'POST', ['comment' => 'Denied']); $assert($status === 401, 'Discussion requires identity');
    [$status] = $request('/api/articles/' . $id . '/comments', 'POST', ['comment' => 'Denied'], 'member', false); $assert($status === 403, 'Discussion requires CSRF');
    [$status, $data] = $request('/api/articles/' . $id . '/comments', 'POST', ['comment' => 'Shared article comment'], 'admin');
    $commentId = (int) ($data['data']['comment_id'] ?? 0);
    $assert($status === 201 && $commentId > 0 && $data['data']['target']['kind'] === 'article', 'Blog-only HTTP creates a real shared comment');
    [$status, $data] = $request('/api/articles/' . $id . '/comments', 'POST', ['comment' => 'Reply', 'parent_id' => $commentId], 'member');
    $replyId = (int) ($data['data']['comment_id'] ?? 0);
    $assert($status === 201 && $data['data']['items'][0]['replies'][0]['id'] === $replyId, 'Replies preserve their publication and parent');
    [$status] = $request('/api/articles/' . $httpId . '/comments', 'POST', ['comment' => 'Foreign parent', 'parent_id' => $commentId], 'member'); $assert($status === 404, 'Another publication cannot borrow a parent');
    [$status] = $request('/api/articles/' . $id . '/comments/' . $commentId, 'PATCH', ['comment' => 'Stolen edit'], 'member'); $assert($status === 403, 'Unrelated actor cannot edit a comment');
    [$status] = $request('/api/articles/' . $httpId . '/comments/' . $commentId, 'DELETE', [], 'admin'); $assert($status === 404, 'Direct comment ID remains bound to its publication');
    [$status, $data] = $request('/api/articles/' . $id . '/comments/' . $commentId, 'PATCH', ['comment' => 'Edited article comment'], 'admin'); $assert($status === 200 && $data['data']['items'][0]['body'] === 'Edited article comment', 'Comment edit uses shared history and text preparation');
    [$status, $data] = $request('/api/articles/' . $id . '/comments/' . $commentId, actor: 'admin'); $assert($status === 200 && count($data['data']['revisions']) === 1, 'Authorized lazy editor exposes canonical revisions');
    foreach (['likes/me', 'comments/' . $commentId . '/likes/me'] as $suffix) {
        [$status] = $request('/api/articles/' . $id . '/' . $suffix, 'PUT', [], 'member'); $assert($status === 200, 'Shared reaction creates one row');
        [$status] = $request('/api/articles/' . $id . '/' . $suffix, 'PUT', [], 'member'); $assert($status === 200, 'Repeated desired state is idempotent');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM blog_article_likes WHERE article_id = ' . $id)->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM blog_comment_likes WHERE comment_id = ' . $commentId)->fetchColumn() === 1, 'Repeated reactions do not duplicate data');
    [$status] = $request('/api/articles/' . $future . '/comments', 'POST', ['comment' => 'Future'], 'admin'); $assert($status === 404, 'Future article cannot be discussed through a direct ID');
    $pdo->exec("UPDATE users SET status = 'ban' WHERE id = 1");
    [$status] = $request('/api/articles/' . $id . '/discussion'); $assert($status === 404, 'Inactive publication author has no readable discussion');
    $pdo->exec("UPDATE users SET status = 'active' WHERE id = 1");
    $pdo->exec("UPDATE users SET status = 'ban' WHERE id = 2");
    [$status] = $request('/api/articles/' . $id . '/comments', 'POST', ['comment' => 'Invisible parent', 'parent_id' => $replyId], 'admin'); $assert($status === 404, 'Inactive comment author cannot become a public reply target');
    [$status] = $request('/api/articles/' . $id . '/comments/' . $replyId . '/likes/me', 'PUT', [], 'admin'); $assert($status === 404, 'Invisible comment cannot be liked through its direct ID');
    $pdo->exec("UPDATE users SET status = 'active' WHERE id = 2");
    [$status] = $request('/api/articles/' . $id . '/comments/' . $commentId, 'DELETE', [], 'admin'); $assert($status === 200, 'Shared delete removes a parent');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM blog_comments WHERE id IN (' . $commentId . ',' . $replyId . ')')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM blog_comment_likes WHERE comment_id = ' . $commentId)->fetchColumn() === 0, 'Parent deletion removes replies and dependent reactions');
    [$status] = $request('/api/articles/' . $id . '/likes/me', 'DELETE', [], 'member'); $assert($status === 200, 'Reaction can be removed without Social');
    [$status, $data] = $request('/api/articles/' . $httpId, 'PUT', ['title' => 'HTTP edit', 'body' => 'Edited', 'status' => 'hidden'], 'admin');
    $assert($status === 200 && ($data['data']['status'] ?? '') === 'hidden', 'API edits and hides existing articles');
    [$status] = $request('/articles/' . $httpId); $assert($status === 404, 'Hidden article has no public page');
    [$status, , $html] = $request('/admin/articles/' . $httpId, actor: 'admin');
    $assert($status === 200 && !str_contains($html, '<textarea') && str_contains($html, 'data-article-open="' . $httpId . '"'), 'Direct editor opens the common list with an on-demand resource, without pre-rendered fields');
    [$status, $data] = $request('/api/articles/' . $httpId . '/management', actor: 'admin');
    $assert($status === 200 && $data['data']['item']['title'] === 'HTTP edit', 'Admin editor loads the current article through an authorized resource');
    foreach (['/api/articles/management', '/api/articles/creation', '/api/articles/' . $httpId . '/management'] as $path) {
        [$status] = $request($path); $assert($status === 401, 'Article management resource requires administrator identity: ' . $path);
        [$status] = $request($path, actor: 'member'); $assert($status === 403, 'Regular account cannot access article management: ' . $path);
    }
    [$status, $data] = $request('/api/articles/management?q=HTTP&status=hidden&per_page=10&sort=title&direction=desc', actor: 'admin');
    $assert($status === 200 && count($data['data']['items']) === 1 && $data['data']['items'][0]['id'] === $httpId && $data['meta']['pagination']['per_page'] === 10, 'Private article selection combines literal search, visibility and pagination');
    $assert(!isset($data['data']['items'][0]['body']), 'List does not fetch or expose full editor bodies');
    [$status, , $html] = $request('/admin/articles', actor: 'admin');
    $assert($status === 200 && str_contains($html, 'HTTP edit') && str_contains($html, 'noindex'), 'Private archive keeps hidden articles accessible to the editor');
    [$status, $data] = $request('/api/articles/' . $httpId, 'PUT', ['title' => '', 'body' => 'Preserved draft'], 'admin');
    $assert($status === 422 && isset($data['error']['details']['errors']['title']), 'Invalid editor mutation returns field-level validation');
    [$status] = $request('/api/articles/' . $httpId, 'DELETE', [], 'admin'); $assert($status === 200, 'Admin deletes through the API');
    $rawUrl = 'https://example.test/reference?original=1#part';
    $link = TinyCat\Core\Text\LinkParser::fromRaw($rawUrl);
    $pdo->prepare('INSERT INTO blog_links(normalized_url, url_hash, provider, link_type, title, description, image_url, video_id, audio_url, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$link['normalized_url'], $link['url_hash'], 'web', 'web', 'Cached reference', 'Fixture summary', '', '', '', date('Y-m-d H:i:s')]);
    $linkArticle = Articles::save(['title' => 'Shared reference', 'body' => 'Read ' . $rawUrl . ' @2'], $admin);
    [$status, $resource] = $request('/api/articles/' . $linkArticle);
    $assert($status === 200 && count($resource['data']['links']) === 1 && str_contains($resource['data']['links'][0]['outbound_url'], '/api/articles/' . $linkArticle . '/links/'), 'Article resources use shared canonical link cards with owner-bound navigation: ' . json_encode($resource));
    [$status, $resolved] = $request('/api/articles/' . $linkArticle . '/links/' . $link['url_hash']);
    $assert($status === 200 && $resolved['data']['original_url'] === $rawUrl, 'Article outbound resolution preserves the original query and fragment');
    [$status] = $request('/api/articles/' . $future . '/links/' . $link['url_hash']); $assert($status === 404, 'Hidden or future content cannot authorize an outbound URL');
    [$status] = $request('/api/articles/' . $linkArticle . '/links/' . str_repeat('0', 64) . '?url=https://evil.test'); $assert($status === 404, 'Unrelated target parameters cannot turn content navigation into an open redirect');
    [$status, , $page] = $request('/articles/' . $linkArticle);
    $assert($status === 200 && str_contains($page, 'status-link-card') && str_contains($page, 'publishing-text'), 'Article HTML renders the same shared text and link components as other publications');
    Articles::delete($linkArticle, $admin);
    if (extension_loaded('gd')) {
        $file = $workspace . '/article-fixture.png'; $bitmap = imagecreatetruecolor(12, 12); imagepng($bitmap, $file);
        [$status, $created] = $request('/api/articles', 'POST', ['title' => 'Shared picture', 'body' => 'Shared picture body', 'image' => new CURLFile($file, 'image/png', 'image.png')], 'admin', true, true);
        $imageArticle = (int) ($created['data']['id'] ?? 0);
        $assert($status === 201 && $imageArticle > 0 && str_starts_with($created['data']['image']['url'] ?? '', '/uploads/status-images/'), 'Blog image upload uses the common multipart and storage pipeline');
        [$status, , $imagePage] = $request('/articles/' . $imageArticle);
        $imageDocument = new DOMDocument(); @$imageDocument->loadHTML($imagePage); $imageXpath = new DOMXPath($imageDocument);
        $assert($status === 200 && str_ends_with($imageXpath->evaluate('string(//meta[@property="og:image"]/@content)'), $created['data']['image']['url']) && str_contains($imageXpath->evaluate('string(//script[@type="application/ld+json"])'), 'ImageObject'), 'Article media also uses the shared Open Graph and structured image metadata');
        $record = TinyCat\Blog\Images::find($imageArticle);
        $oldFile = $workspace . '/uploads/status-images/' . $record['path'];
        $hashBefore = hash_file('sha256', $oldFile); $filesBefore = glob($workspace . '/uploads/status-images/*/*/*.webp');
        $pdo->exec("CREATE TRIGGER reject_blog_image BEFORE UPDATE ON blog_articles BEGIN SELECT RAISE(ABORT, 'injected image failure'); END");
        [$status] = $request('/api/articles/' . $imageArticle, 'POST', ['_method' => 'PUT', 'title' => 'Failed picture', 'body' => 'Picture rollback', 'image' => new CURLFile($file, 'image/png', 'image.png')], 'admin', true, true);
        $pdo->exec('DROP TRIGGER reject_blog_image');
        $assert($status === 500 && hash_file('sha256', $oldFile) === $hashBefore && TinyCat\Blog\Images::find($imageArticle)['path'] === $record['path'] && glob($workspace . '/uploads/status-images/*/*/*.webp') === $filesBefore, 'Failed article specialization rolls back image replacement and removes the newly uploaded orphan');
        [$status, $removed] = $request('/api/articles/' . $imageArticle, 'PUT', ['title' => 'Shared picture', 'body' => 'Picture remains text', 'remove_image' => true], 'admin');
        $assert($status === 200 && ($removed['data']['image']['url'] ?? '') === '' && !is_file($oldFile), 'Successful article image removal deletes the file after commit');
        Articles::delete($imageArticle, $admin);
    }
    [$status] = $request('/login'); $assert($status === 200, 'Accounts login uses the Blog shell');
    $pdo->exec("UPDATE module_installations SET status = 'failed' WHERE module_id = 'tinycat.blog'");
    [$status, $data] = $request('/api/articles/' . $id);
    $assert($status === 503 && ($data['code'] ?? '') === 'database_not_ready', 'Unready schema blocks API with the common JSON envelope');
    $pdo->exec("UPDATE module_installations SET status = 'ready' WHERE module_id = 'tinycat.blog'");
    $assert(!is_file($workspace . '/product-loads.log'), 'HTTP boot never loaded an absent social product class');
} finally { if (is_resource($server)) { proc_terminate($server); proc_close($server); } }
foreach (get_declared_classes() as $class) { $assert(!preg_match('/^TinyCat\\\\(?:Status|Users|Bots|Notifications|Moderation|Settings|Site|Search)\\\\/', $class), 'No product class loaded: ' . $class); }
$completed = true;
echo "PASS isolated Blog assembly, installation, upgrade, publishing, HTTP, editor and build ({$checks} checks)\n";
