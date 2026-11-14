<?php
declare(strict_types=1);

// Continues the isolated Blog fixture with its existing database and articles.
use TinyCat\Core\Modules\{Deployment, Distribution, Loader, Resources};
use TinyCat\Database\{Connection, ModuleSchemas};

$checks = 0;
$completed = false;
register_shutdown_function(static function () use (&$completed): void { if (!$completed) { fwrite(STDERR, "Combined assertions did not reach completion.\n"); exit(1); } });
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$pdo = new PDO('sqlite:' . $workspace . '/blog.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON'); Connection::set($pdo);
$selection = require $workspace . '/App/available-modules.php'; unset($selection['tinycat.installation']);
$base = array_keys(require $workspace . '/App/Modules/Blog/selection.php');
$plan = Distribution::describe($workspace, 'fixture.platform', $selection, $base);
file_put_contents($workspace . '/App/distribution.json', json_encode($plan, JSON_THROW_ON_ERROR));
Deployment::write($workspace, $plan, $base, $base, TinyCat\Support\Version::CURRENT);
$run = static function (array $arguments) use ($workspace): void {
    $process = proc_open([PHP_BINARY, $workspace . '/App/Console.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workspace);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start combined fixture CLI.'); }
    fclose($pipes[0]); $text = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Combined fixture CLI: ' . $text . $error); }
};
$run(['activate', 'tinycat.social']);
$modules = Loader::load($workspace); Resources::configure($modules->catalog);
$assert(ModuleSchemas::ready($pdo, Distribution::definitions($modules, false)), 'All selected owners have standalone schema histories');
$administrator = Connection::find('users', ['id' => 1]);
$article = TinyCat\Blog\Articles::save(['title' => 'Combined article', 'body' => 'Coexistence marker #combined'], $administrator);
TinyCat\Database\SchemaInspection::reserveIds($pdo, 'social_posts', $article);
$post = TinyCat\Social\Content\PostCreation::create(1, TinyCat\Social\Content\PostCreation::fromText('Coexistence status #combined'), []);
$assert($post === $article && TinyCat\Social\Posts\Posts::status_find($post)['body'] !== TinyCat\Blog\Articles::find($article)['body'], 'Equal numeric IDs resolve independently through owner repositories');
$assert(array_column(TinyCat\Social\Posts\Feed::public_status_items_cursor(30), 'id') === [$post], 'Social feed excludes existing and new articles');
$assert(array_column(TinyCat\Social\Search\Queries::public_search_content_rows('Coexistence', 20), 'id') === [$post], 'Social search keeps canonical status ownership');
$combinedSearch = TinyCat\Core\Discovery\Search::query('Coexistence');
$searchGroups = array_column($combinedSearch['collections'], null, 'id');
$assert(array_column($searchGroups['content']['items'], 'id') === [$post] && array_column($searchGroups['articles']['items'], 'id') === [$article], 'Generic search combines active owners with separate canonical resources');
$assert(TinyCat\Http\LocaleRouting::url('/search/all?q=Coexistence', 'cs') === '/cs/search/all?q=Coexistence' && TinyCat\Http\LocaleRouting::split('/cs/search/all')['path'] === '/search/all', 'Generic search preserves explicit interface languages without the social AJAX route');
$assert(TinyCat\Core\Discovery\Sitemap::count('articles') > 1 && TinyCat\Core\Discovery\Sitemap::hasSection('status'), 'Combined sitemap retains both article and status sources');
$assert(TinyCat\Social\Presentation\Sitemap::count('status') === 1 && TinyCat\Social\Posts\AuthorStatistics::author_activity_stats(1)['posts'] === 1, 'Sitemap and social statistics exclude articles');
$text = 'Shared @Editor #combined';
$beforeSocial = TinyCat\Social\Content\PostCreation::fromText($text, 'article')->body;
TinyCat\Social\Posts\Registration::initialize();
$assert(TinyCat\Social\Content\PostCreation::fromText($text, 'article')->body === $beforeSocial, 'Loading Social cannot alter article mention preparation');
$assert(TinyCat\Social\Moderation\Restrictions::moderation_user_post_count(1) === 1 && TinyCat\Social\Moderation\Restrictions::moderation_user_reputation(['id' => 1, 'role' => 'user', 'created_at' => date('Y-m-d H:i:s', time() - 8 * 86400)]) === 'normal', 'Articles do not increase social publication counts or reputation');
TinyCat\Blog\Module::initialize();
$socialTables = TinyCat\Social\Module::tables();
$assert(in_array('social_comments', $socialTables, true) && !in_array('blog_comments', $socialTables, true) && TinyCat\Core\Modules\Providers::schemaOwners($modules->catalog)['tinycat.status'] === 'tinycat.social', 'Social owns its complete storage and retained historical stream');
$actor = Connection::find('users', ['id' => 2]);
$beforeNotifications = Connection::count('notifications');
$articleComment = TinyCat\Blog\Discussion::write($article, $actor, 'comment', body: 'Article-only discussion');
$assert(Connection::count('notifications') === $beforeNotifications, 'Blog discussion does not emit Social notifications');
$postComment = TinyCat\Social\Content\Interactions::write('post', $post, $actor, 'comment', body: 'Social discussion');
$pdo->exec('UPDATE blog_comments SET id = ' . (int) $postComment['comment_id'] . ' WHERE id = ' . (int) $articleComment['comment_id']);
$articleComment['comment_id'] = $postComment['comment_id'];
$assert(TinyCat\Blog\Discussion::resource($article, $actor)['items'][0]['body'] !== TinyCat\Social\Content\Interactions::read('post', $post, $actor)['items'][0]['body'], 'Same article/post and comment IDs retain independent discussion bodies');
$assert(Connection::count('notifications') > $beforeNotifications, 'Social retains its notification event policy');
TinyCat\Blog\Discussion::write($article, $actor, 'react', desired: true);
TinyCat\Blog\Discussion::write($article, $actor, 'comment_like', (int) $articleComment['comment_id'], desired: true);
$activity = TinyCat\Social\Posts\AuthorStatistics::author_activity_stats(2);
$assert($activity['comments'] === 1 && $activity['likes_given'] === 0, 'Article discussion and reactions do not leak into Social activity');
$sharedPath = '2000/01/' . str_repeat('e', 32) . '.webp';
$sharedFile = $workspace . '/uploads/status-images/' . $sharedPath;
if (!is_dir(dirname($sharedFile))) { mkdir(dirname($sharedFile), 0700, true); }
file_put_contents($sharedFile, 'retained-owner-fixture');
Connection::insert('blog_article_images', ['article_id' => $article, 'path' => $sharedPath, 'width' => 1, 'height' => 1, 'bytes' => 22]);
Connection::insert('social_post_images', ['content_id' => $post, 'path' => $sharedPath, 'width' => 1, 'height' => 1, 'bytes' => 22]);
$run(['deactivate', 'tinycat.blog']);
Resources::configure(Loader::load($workspace)->catalog);
Connection::delete('social_post_images', ['content_id' => $post]);
TinyCat\Social\Content\ImageStorage::delete($sharedPath);
$assert(is_file($sharedFile), 'Deleting a Social attachment retains a file referenced only by installed inactive Blog');
Connection::delete('blog_article_images', ['article_id' => $article]);
TinyCat\Social\Content\ImageStorage::delete($sharedPath); clearstatcache(true, $sharedFile);
$assert(!is_file($sharedFile), 'The shared file mechanism removes an attachment after its last owner releases it');
$run(['activate', 'tinycat.blog']);
Resources::configure(Loader::load($workspace)->catalog);
$sharedFeedUrl = 'https://93.184.216.34/shared-owner-feed.xml';
$sharedFeed = TinyCat\Core\Feeds\FeedParser::parse('<rss xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item><guid>shared-target</guid><title>Shared input</title><description>Short status summary</description><content:encoded><![CDATA[' . str_repeat('Complete article body. ', 160) . ']]></content:encoded></item></channel></rss>');
$botId = (int) Connection::insert('users', ['username' => 'feedfixture', 'password' => 'fixture', 'role' => 'bot', 'status' => 'active']);
$socialSourceId = (int) Connection::insert('bot_sources', ['bot_user_id' => $botId, 'name' => 'Shared source', 'feed_url' => $sharedFeedUrl, 'feed_hash' => TinyCat\Social\Automation\Importer::feedSourceHash($sharedFeedUrl), 'post_template' => "{{title}}\n\n{{description}}"]);
$blogSourceId = TinyCat\Blog\FeedSources::save(['name' => 'Shared source', 'feed_url' => $sharedFeedUrl, 'author_id' => 1]);
$socialPipeline = new TinyCat\Core\Feeds\FeedPipeline(new TinyCat\Social\Automation\FeedStore(), new TinyCat\Social\Automation\FeedPublication());
$statusImport = $socialPipeline->import(Connection::find('bot_sources', ['id' => $socialSourceId]), $sharedFeed);
$articleImport = TinyCat\Blog\FeedSources::pipeline()->import(TinyCat\Blog\FeedSources::runtime(TinyCat\Blog\FeedSources::find($blogSourceId)), $sharedFeed);
$feedPost = Connection::find('social_posts', ['id' => $statusImport['content_id']]);
$feedArticle = TinyCat\Blog\Articles::find((int) $articleImport['content_id'], false);
$assert($statusImport['imported_count'] === 1 && $articleImport['imported_count'] === 1 && $feedPost['kind'] === 'post' && str_contains($feedPost['body'], 'Short status summary') && strlen($feedArticle['body']) > 2000, 'One normalized feed uses two explicit targets, keeping the social summary and full article independent');
$assert($socialPipeline->import(Connection::find('bot_sources', ['id' => $socialSourceId]), $sharedFeed)['imported_count'] === 0 && TinyCat\Blog\FeedSources::pipeline()->import(TinyCat\Blog\FeedSources::runtime(TinyCat\Blog\FeedSources::find($blogSourceId)), $sharedFeed)['imported_count'] === 0, 'Each source owner independently deduplicates the same external GUID');
TinyCat\Social\Content\Posts::delete((int) $statusImport['content_id']);
TinyCat\Blog\Articles::delete((int) $articleImport['content_id'], $administrator);
Connection::delete('blog_sources', ['id' => $blogSourceId]); Connection::delete('users', ['id' => $botId]);
$socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
if ($socket === false) { throw new RuntimeException('Cannot allocate combined fixture port.'); }
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $workspace . '/sessions', '-S', $address, $sourceRoot . '/tests/fixtures/blog/module-worker.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace . '/combined-server.log', 'a'], 2 => ['file', $workspace . '/combined-server.log', 'a']], $pipes, $workspace,
    array_merge(getenv(), ['TINYCAT_BLOG_WORKSPACE' => $workspace, 'TINYCAT_COMBINED_FIXTURE' => '1']));
if (!is_resource($server)) { throw new RuntimeException('Cannot start combined fixture HTTP worker.'); }
fclose($pipes[0]);
$cookies = []; $csrf = '';
$request = static function (string $path, bool $admin = false, ?array $payload = null, bool $withCsrf = true) use ($address, &$cookies, &$csrf): array {
    $curl = curl_init('http://' . $address . $path);
    $headers = $admin ? ['Authorization: Bearer admin'] : [];
    if ($cookies !== []) { $headers[] = 'Cookie: ' . implode('; ', $cookies); }
    if ($payload !== null) { $headers[] = 'Content-Type: application/json'; if ($withCsrf) { $headers[] = 'X-CSRF-Token: ' . $csrf; } curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$cookies): int { if (preg_match('/^Set-Cookie:\s*([^=]+=[^;]*)/i', $header, $m)) { $cookies[explode('=', $m[1], 2)[0]] = $m[1]; } return strlen($header); }]);
    $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if (is_string($body) && preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) { $csrf = $m[1]; }
    return [$status, (string) $body];
};
$changeModules = static function (string $id, bool $enabled) use ($request, $assert): void {
    [$status, $json] = $request('/api/modules', true); $data = json_decode($json, true)['data'] ?? [];
    $assert($status === 200 && !empty($data['manageable']), 'Administrator sees the canonical module inventory');
    [$status, $json] = $request('/api/modules/activation', true, ['id' => $id, 'enabled' => $enabled, 'revision' => $data['revision']]);
    $assert($status === 201 && (json_decode($json, true)['data']['redirect'] ?? '') === '/admin/modules', 'Module mutation uses signed lifecycle with a full-page refresh: ' . $json);
};

try {
    for ($attempt = 0; $attempt < 50; $attempt++) { [$status] = $request('/blog'); if ($status === 200) { break; } usleep(100000); }
    foreach (['/', '/social', '/blog', '/articles/' . $article, '/status/' . $post] as $path) { [$status] = $request($path); $assert($status === 200, 'Combined public route: ' . $path . ' HTTP ' . $status . ' ' . (string) file_get_contents($workspace . '/combined-server.log')); }
    [$status, $html] = $request('/status/' . $article); $assert($status === 200 && str_contains($html, 'Coexistence status') && !str_contains($html, 'Coexistence marker'), 'Social route resolves its own row when numeric IDs overlap');
    [$status] = $request('/api/statuses/' . $article . '?panel=edit', true); $assert($status === 200, 'Social editor resolves its own same-ID status');
    [$status] = $request('/status/' . $article . '/embed'); $assert($status === 200, 'Social embed resolves its own same-ID status');
    [$status, $json] = $request('/oembed?url=' . rawurlencode('http://' . $address . '/articles/' . $article));
    $assert($status === 200 && (json_decode($json, true)['title'] ?? '') === 'Combined article', 'Combined oEmbed chooses Blog by canonical path');
    [$status, $json] = $request('/oembed?url=' . rawurlencode('http://' . $address . '/status/' . $post));
    $assert($status === 200 && str_contains(json_decode($json, true)['html'] ?? '', '/status/' . $post . '/embed'), 'Combined oEmbed retains the Social provider');
    foreach (['/admin', '/admin/modules', '/admin/articles', '/admin/cron', '/admin/users'] as $path) { [$status] = $request($path, true); $assert($status === 200, 'Combined administration route: ' . $path); }
    [$status] = $request('/api/modules'); $assert($status === 401, 'Module inventory is private');
    [$status, $moduleHtml] = $request('/admin/modules', true);
    $assert($status === 200 && str_contains($moduleHtml, '/api/modules/activation') && str_contains($moduleHtml, 'tinycat.blog'), 'Module administration is in the common navigation and renders controls');
    [$status] = $request('/api/modules/activation', true, ['id' => 'tinycat.blog', 'enabled' => false, 'revision' => 'stale'], false); $assert($status === 403, 'Module changes require CSRF');
    [$status] = $request('/api/modules/activation', true, ['id' => 'tinycat.blog', 'enabled' => false, 'revision' => 'stale']); $assert($status === 409, 'Stale selections cannot overwrite newer activation state');
    [, $json] = $request('/api/modules', true); $moduleState = json_decode($json, true)['data'];
    $assert(array_column($moduleState['items'], 'id') === ['tinycat.social', 'tinycat.blog'] && $moduleState['groups'] === [], 'Manager exposes two real modules without framework entries or virtual Social groups');
    $assert(array_merge(...array_column($moduleState['items'], 'requires')) === [], 'Mandatory framework capabilities are not presented as selectable module dependencies');
    [$status] = $request('/api/modules/activation', true, ['id' => 'tinycat.accounts', 'enabled' => false, 'revision' => $moduleState['revision']]); $assert($status === 422, 'Core is not an activatable module');
    [$status] = $request('/api/modules/activation', true, ['id' => 'tinycat.site', 'enabled' => false, 'revision' => $moduleState['revision']]); $assert($status === 422, 'An internal Social fragment has no separate activation');
    [$status, $adminArticles] = $request('/admin/articles', true);
    $assert($status === 200 && str_contains($adminArticles, 'table-actions-cell') && str_contains($adminArticles, '/api/articles/creation') && str_contains($adminArticles, 'admin-list-toolbar'), 'Article management uses shared list components and a resource creation action');
    $makeUpload = require $sourceRoot . '/tests/fixtures/blog/upload-package.php';
    $makeUpload($workspace, '1.0.0', 'Uploaded module is active');
    $makeUpload($workspace, '2.0.0', 'Updated module is active');
    passthru('node ' . escapeshellarg($sourceRoot . '/tests/fixtures/blog/ui.cjs') . ' ' . escapeshellarg('http://' . $address) . ' ' . escapeshellarg((string) $article) . ' ' . escapeshellarg($workspace) . ' 2>&1', $captureExit);
    if ($captureExit !== 0 && getenv('TINYCAT_UI_DIAGNOSTICS_DIR')) {
        copy($workspace . '/combined-server.log', getenv('TINYCAT_UI_DIAGNOSTICS_DIR') . '/blog-server.log');
        if (is_file($workspace . '/fixture-errors.log')) { copy($workspace . '/fixture-errors.log', getenv('TINYCAT_UI_DIAGNOSTICS_DIR') . '/blog-php-errors.log'); }
    }
    $assert($captureExit === 0, 'Browser verifies administration and article layouts');
    TinyCat\Core\Settings\Store::setSetting('site.homepage', 'tinycat.blog.home', 'string', 'site');
    [$status, $html] = $request('/'); $assert($status === 200 && str_contains($html, 'Combined article'), 'Configured Blog homepage wins without a route collision');
    [$status] = $request('/api/site/pages?path=/'); $assert($status === 404, 'Social AJAX navigation cannot replace the configured Blog homepage');
    [$status, $json] = $request('/api/site/pages?path=/social&feed=following'); $assert($status === 200 && str_contains($json, '/social'), 'Explicit Social feed keeps its own navigation path');
    $before = (int) Connection::value('SELECT COUNT(*) FROM social_posts');
    $changeModules('tinycat.social', false);
    [$status, $json] = $request('/api/search/query?q=Coexistence');
    $assert($status === 200 && array_column(json_decode($json, true)['data']['collections'], 'id') === ['articles'], 'Social deactivation removes its search sources on the next request');
    [$status, $xml] = $request('/sitemap.xml');
    $assert($status === 200 && str_contains($xml, 'sitemap-articles') && !str_contains($xml, 'sitemap-status'), 'Social deactivation changes the sitemap directory immediately');
    foreach (['/', '/blog', '/articles/' . $article, '/login'] as $path) { [$status] = $request($path); $assert($status === 200, 'Platform route without Social: ' . $path); }
    foreach (['/admin', '/admin/modules', '/admin/articles', '/admin/cron', '/admin/users'] as $path) { [$status] = $request($path, true); $assert($status === 200, 'Administration without Social: ' . $path); }
    [$status] = $request('/api/articles/' . $article . '/discussion'); $assert($status === 200, 'Article discussion remains available without Social');
    foreach (['/social', '/api/statuses/' . $post, '/api/statuses/' . $post . '/comments'] as $path) { [$status] = $request($path); $assert($status === 404, 'Disabled Social exposes no route: ' . $path); }
    Resources::configure(Loader::load($workspace)->catalog);
    $templates = TinyCat\Core\Mail\Templates::email_template_keys();
    $assert(in_array('welcome', $templates, true) && !in_array('notification_content_mention', $templates, true) && in_array('password_reset', $templates, true), 'Accounts owns recovery/welcome templates without social templates');
    $assert(str_contains(json_encode(TinyCat\Core\Settings\Sections::current(), JSON_THROW_ON_ERROR), 'blog.blocked_urls'), 'Blog retains its own URL policy without Social');
    $assert((int) Connection::value('SELECT COUNT(*) FROM social_posts') === $before, 'Deactivation preserves every publication');
    $pdo->exec("INSERT INTO users(id, username, password, role, status) VALUES (3, 'RetainedWriter', 'fixture', 'user', 'active')");
    $removedComment = (int) Connection::insert('social_comments', ['content_id' => $post, 'user_id' => 3, 'body' => 'Retained inactive subject']);
    $removedReply = (int) Connection::insert('social_comments', ['content_id' => $post, 'parent_id' => $removedComment, 'user_id' => 2, 'body' => 'Dependent reply']);
    Connection::insert('social_comment_likes', ['comment_id' => $removedReply, 'user_id' => 1]);
    Connection::insert('blog_article_likes', ['article_id' => $article, 'user_id' => 3]);
    Connection::insert('notifications', ['user_id' => 1, 'actor_id' => 3, 'type' => 'content_comment', 'notification_key' => 'retained-cleanup-test', 'content_id' => $post, 'comment_id' => $removedReply]);
    [$status, $json] = $request('/api/users/3', true, ['_method' => 'DELETE']);
    $assert($status === 200 && Connection::find('users', ['id' => 3]) === null, 'Core account deletion includes installed inactive Social participant: ' . $json . substr((string) file_get_contents($workspace . '/combined-server.log'), -4000));
    $assert(Connection::find('social_comments', ['id' => $removedComment]) === null && Connection::find('social_comments', ['id' => $removedReply]) === null && (int) Connection::value('SELECT COUNT(*) FROM notifications WHERE notification_key = ?', ['retained-cleanup-test']) === 0, 'Inactive-owner cleanup removes dependent replies, reactions and social notification subjects');
    $changeModules('tinycat.social', true);
    [$status] = $request('/status/' . $post); $assert($status === 200, 'Social reactivation exposes its retained status');
    $changeModules('tinycat.blog', false);
    [$status, $json] = $request('/api/search/query?q=Coexistence');
    $assert($status === 200 && !in_array('articles', array_column(json_decode($json, true)['data']['collections'], 'id'), true), 'Blog deactivation removes its search source while Social remains');
    [$status, $xml] = $request('/sitemap.xml');
    $assert($status === 200 && !str_contains($xml, 'sitemap-articles') && str_contains($xml, 'sitemap-status'), 'Blog deactivation removes its sitemap contribution');
    [$status] = $request('/blog'); $assert($status === 404, 'Blog can independently deactivate');
    [$status] = $request('/api/articles/' . $article . '/discussion'); $assert($status === 404, 'Inactive article owner exposes no discussion endpoint');
    [$status] = $request('/'); $assert($status === 200, 'An unavailable configured homepage falls back to an active provider');
    $assert((int) Connection::value('SELECT COUNT(*) FROM social_posts') === $before, 'Independent Blog deactivation retains data');
    $changeModules('tinycat.social', false);
    foreach (['/privacy', '/llms.txt', '/login'] as $path) { [$status] = $request($path); $assert($status === 200, 'Core-only public route: ' . $path); }
    [$status, $html] = $request('/admin', true);
    $assert($status === 200 && str_contains($html, 'id="dashboard-cache"') && !str_contains($html, 'recent-comments'), 'Core-only dashboard retains technical diagnostics');
    [$status, $html] = $request('/llms-full.txt');
    $assert($status === 200 && !str_contains($html, '## Social'), 'Core-only information excludes inactive owners');
    $changeModules('tinycat.blog', true);
    [$status] = $request('/articles/' . $article); $assert($status === 200, 'Blog reactivation retains the canonical article');
} finally { proc_terminate($server); proc_close($server); }
$completed = true;
echo "PASS combined Blog/Social, standalone owner schemas, CLI lifecycle, settings, templates and HTTP ({$checks} checks)\n";
