<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test is available only from the command line.\n");
    exit(1);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP write-path integrity: pdo_sqlite is unavailable.\n";
    exit(0);
}

$root = dirname(__DIR__);
define('TINYCAT', true);
require_once $root . '/App/autoload.php';
require_once $root . '/App/Users/Avatar.php';
require_once $root . '/App/Status/ImageStorage.php';
require_once $root . '/App/Status/LinkParser.php';
require_once $root . '/App/Status/Metadata.php';
require_once $root . '/App/Users/Roles.php';

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$booted = new ReflectionProperty(\TinyCat\Support\Config::class, 'booted');
$booted->setValue(null, true);
$config = new ReflectionProperty(\TinyCat\Support\Config::class, 'config');
$config->setValue(null, [
    'app' => ['url' => 'http://localhost'],
    'database' => ['driver' => 'sqlite'],
    'install' => ['complete' => true, 'locale' => 'en'],
    'i18n' => ['locale' => 'en'],
    'datetime' => ['timezone' => 'UTC'],
    'cache' => ['driver' => 'filesystem'],
    'moderation' => ['blocked_urls' => 'blocked.example'],
]);
\TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};
$value = static fn (string $sql): mixed => $database->query($sql)->fetchColumn();

foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, email TEXT, email_notifications INTEGER NOT NULL DEFAULT 0, locale TEXT, theme TEXT, avatar_exists INTEGER DEFAULT 0, updated_at TEXT, role TEXT NOT NULL, bio TEXT NOT NULL, password TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT, muted_until TEXT)',
    "CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT NOT NULL DEFAULT '', author_id INTEGER NOT NULL, published_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00', edit_locked_at TEXT)",
    'CREATE TABLE content_likes (content_id INTEGER NOT NULL, user_id INTEGER NOT NULL)',
    'CREATE TABLE content_comments (id INTEGER PRIMARY KEY, content_id INTEGER NOT NULL, parent_id INTEGER, user_id INTEGER NOT NULL, body TEXT, created_at TEXT)',
    'CREATE TABLE comment_likes (comment_id INTEGER NOT NULL, user_id INTEGER NOT NULL)',
    'CREATE TABLE terms (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)',
    'CREATE TABLE content_tags (content_id INTEGER NOT NULL, term_id INTEGER NOT NULL, UNIQUE (content_id, term_id))',
    'CREATE TABLE links (id INTEGER PRIMARY KEY AUTOINCREMENT, normalized_url TEXT NOT NULL, url_hash TEXT NOT NULL UNIQUE, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, created_at TEXT, updated_at TEXT)',
    'CREATE TABLE content_links (content_id INTEGER NOT NULL, link_id INTEGER NOT NULL, created_at TEXT, UNIQUE (content_id, link_id))',
    'CREATE TABLE content_images (content_id INTEGER PRIMARY KEY, path TEXT NOT NULL, width INTEGER, height INTEGER, bytes INTEGER)',
    'CREATE TABLE content_reports (id INTEGER PRIMARY KEY, content_id INTEGER NOT NULL, reporter_id INTEGER, status TEXT)',
    'CREATE TABLE notifications (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, actor_id INTEGER, content_id INTEGER, comment_id INTEGER, type TEXT NOT NULL, notification_key TEXT NOT NULL, read_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (user_id, notification_key))',
    'CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT)',
    'CREATE TABLE user_followers (user_id INTEGER NOT NULL, follower_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (user_id, follower_id))',
] as $sql) {
    $database->exec($sql);
}

$database->exec("INSERT INTO users (id, username, email, locale, role, bio, password, status, created_at) VALUES (1, 'owner', NULL, 'en', 'user', 'old bio', 'old-password', 'active', '2025-01-01 00:00:00'), (2, 'actor', NULL, 'en', 'user', '', 'actor-password', 'active', '2025-01-01 00:00:00')");
$database->exec("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (1, 'old-token', '2030-01-01 00:00:00')");
$database->exec('CREATE TRIGGER fail_recovery_token BEFORE INSERT ON password_reset_tokens WHEN NEW.token_hash = \'new-token\' BEGIN SELECT RAISE(ABORT, \'forced recovery failure\'); END');
$recoveryFailed = false;
try {
    \TinyCat\Users\Recovery::auth_recovery_replace_token(1, 'new-token', '2030-01-01 00:00:00');
} catch (PDOException) {
    $recoveryFailed = true;
}
$assert($recoveryFailed, 'A replacement-token failure is propagated.');
$assert($value('SELECT token_hash FROM password_reset_tokens WHERE user_id = 1') === 'old-token', 'Failed token replacement restores the previous token.');
$database->exec('DROP TRIGGER fail_recovery_token');
\TinyCat\Users\Recovery::auth_recovery_replace_token(1, 'active-token', '2030-01-01 00:00:00');
$assert($value('SELECT token_hash FROM password_reset_tokens WHERE user_id = 1') === 'active-token', 'Successful token replacement commits one current token.');
$assert(
    \TinyCat\Users\Recovery::auth_recovery_consume_token(1, 'active-token', 'first-password', '2026-08-09 12:00:00'),
    'A current recovery token is consumed atomically.'
);
$assert(
    !\TinyCat\Users\Recovery::auth_recovery_consume_token(1, 'active-token', 'second-password', '2026-08-09 12:00:01'),
    'A consumed recovery token cannot be reused.'
);
$assert($value('SELECT password FROM users WHERE id = 1') === 'first-password', 'Token reuse cannot overwrite the new password.');

$tagText = implode(' ', array_map(static fn (int $id): string => '#tag' . $id, range(1, 12)));
$assert(count(\TinyCat\Status\Tags::status_tags_from_text($tagText)) === 12, 'Tag parsing does not truncate at ten unique tags.');
$assert(count(\TinyCat\Status\Actions::status_require_valid_tags($tagText)) === 12, 'Tag validation accepts more than ten unique tags.');
$assert(\TinyCat\Status\Tags::status_tag_normalize(str_repeat('a', 33)) === '', 'The storage-backed per-tag length bound remains.');

$youtube = \TinyCat\Status\LinkParser::fromRaw('https://youtu.be/abc123');
$assert(is_array($youtube) && !array_key_exists('embed_url', $youtube), 'Video parser retains only the canonical video identifier.');
$assert(\TinyCat\Status\Links::status_video_embed_url(['provider' => 'youtube', 'video_id' => 'abc123']) === 'https://www.youtube.com/embed/abc123', 'YouTube embed URL is derived from its canonical identifier.');
$assert(\TinyCat\Status\Links::status_video_embed_url(['provider' => 'vimeo', 'video_id' => '987654']) === 'https://player.vimeo.com/video/987654', 'Vimeo embed URL is derived from its canonical identifier.');
$assert(\TinyCat\Status\Links::status_video_embed_url(['provider' => 'dailymotion', 'video_id' => 'x9demo']) === 'https://www.dailymotion.com/embed/video/x9demo', 'Dailymotion embed URL is derived from its canonical identifier.');
$assert(\TinyCat\Status\Links::status_video_embed_url(['provider' => 'unknown', 'video_id' => 'abc123']) === '', 'Unknown video providers cannot produce an embed URL.');
$assert(!array_key_exists('embed_url', \TinyCat\Status\Links::status_link_data((array) $youtube)), 'Link persistence excludes the derived embed URL.');


// Count transport attempts without DNS or any real outbound request.
final class FailedLinkTransport
{
    public mixed $context;
    public static int $attempts = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$attempts++;
        return false;
    }
}
$failedLink = \TinyCat\Status\LinkParser::fromRaw('http://93.184.216.34/unavailable');
$failureKey = 'link-metadata-failure-v1-' . hash('sha256', (string) $failedLink['normalized_url']);
stream_wrapper_unregister('http');
stream_wrapper_register('http', FailedLinkTransport::class);
try {
    $firstFailure = \TinyCat\Status\Links::status_link_prepare_metadata($failedLink, null);
    $secondFailure = \TinyCat\Status\Links::status_link_prepare_metadata($failedLink, null);
    $assert(FailedLinkTransport::$attempts === 1, 'A repeated unavailable preview reuses the short failure cache.');
    $assert($secondFailure['normalized_url'] === $failedLink['normalized_url'], 'A failed preview retains a usable link.');
    $staleLink = $failedLink + ['image_url' => ''];
    $staleLink['title'] = 'Previously fetched title';
    $staleLink['updated_at'] = '2020-01-01 00:00:00';
    $stalePreview = \TinyCat\Status\Links::status_link_prepare_metadata($failedLink, $staleLink);
    $assert($stalePreview['title'] === 'Previously fetched title' && $stalePreview['_metadata_updated_at'] === $staleLink['updated_at'], 'Failure backoff preserves old metadata without renewing its daily TTL.');
    $failureFile = (new ReflectionMethod(\TinyCat\Support\Cache::class, 'jsonFile'))->invoke(null, $failureKey);
    touch($failureFile, time() - 301);
    $retry = \TinyCat\Status\Links::status_link_prepare_metadata($failedLink, $staleLink);
    $assert(FailedLinkTransport::$attempts === 2 && $retry['_metadata_updated_at'] === $staleLink['updated_at'], 'Once failure backoff expires, retry preserves the old success timestamp on another failure.');
    $freshLink = $staleLink;
    $freshLink['updated_at'] = \TinyCat\Support\Dates::dateDb();
    $freshPreview = \TinyCat\Status\Links::status_link_prepare_metadata($failedLink, $freshLink);
    $assert(FailedLinkTransport::$attempts === 2 && $freshPreview['title'] === 'Previously fetched title', 'Fresh successful metadata remains available during failure backoff.');
} finally {
    stream_wrapper_restore('http');
    \TinyCat\Support\Cache::forget($failureKey);
}

$database->exec('INSERT INTO content (id, author_id) VALUES (100, 1), (200, 1), (300, 2)');
$database->exec("INSERT INTO terms (id, name) VALUES (1, 'old')");
$database->exec('INSERT INTO content_tags (content_id, term_id) VALUES (100, 1)');
$database->exec('CREATE TRIGGER fail_tag_attach BEFORE INSERT ON content_tags WHEN NEW.term_id = 3 BEGIN SELECT RAISE(ABORT, \'forced tag failure\'); END');
$tagFailed = false;
try {
    \TinyCat\Status\Tags::status_sync_tags(100, ['alpha', 'beta']);
} catch (PDOException) {
    $tagFailed = true;
}
$assert($tagFailed, 'A non-unique tag relation failure is propagated.');
$assert((int) $value('SELECT term_id FROM content_tags WHERE content_id = 100') === 1, 'Failed tag synchronization restores the old relation.');
$assert((int) $value('SELECT COUNT(*) FROM terms') === 1, 'Failed tag synchronization rolls back newly created terms.');
$database->exec('DROP TRIGGER fail_tag_attach');
\TinyCat\Status\Tags::status_sync_tags(100, \TinyCat\Status\Tags::status_tags_from_text($tagText));
$assert((int) $value('SELECT COUNT(*) FROM content_tags WHERE content_id = 100') === 12, 'Successful tag synchronization persists every valid tag.');

$now = \TinyCat\Support\Dates::dateDb();
$linkColumns = 'normalized_url, url_hash, provider, link_type, title, description, image_url, video_id, created_at, updated_at';
$insertLink = $database->prepare('INSERT INTO links (' . $linkColumns . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insertLink->execute(['https://old.example', 'old', 'web', 'link', 'Old', '', '', '', $now, $now]);
$insertLink->execute(['https://alpha.example', 'alpha', 'web', 'link', 'Alpha title', '', '', '', $now, $now]);
$insertLink->execute(['https://beta.example', 'beta', 'web', 'link', 'Beta title', '', '', '', $now, $now]);
$database->exec("INSERT INTO content_links (content_id, link_id, created_at) VALUES (100, 1, '$now')");
$database->exec('CREATE TRIGGER fail_link_attach BEFORE INSERT ON content_links WHEN NEW.link_id = 3 BEGIN SELECT RAISE(ABORT, \'forced link failure\'); END');
$links = [
    ['normalized_url' => 'https://alpha.example', 'url_hash' => 'alpha'],
    ['normalized_url' => 'https://beta.example', 'url_hash' => 'beta'],
];
$linkFailed = false;
try {
    \TinyCat\Status\Links::status_sync_links(100, $links);
} catch (PDOException) {
    $linkFailed = true;
}
$assert($linkFailed, 'A non-unique link relation failure is propagated.');
$assert((int) $value('SELECT link_id FROM content_links WHERE content_id = 100') === 1, 'Failed link synchronization restores the old relation.');
$database->exec('DROP TRIGGER fail_link_attach');
\TinyCat\Status\Links::status_sync_links(100, $links);
$assert((int) $value('SELECT COUNT(*) FROM content_links WHERE content_id = 100') === 2, 'Successful link synchronization replaces all relations.');


$draft = \TinyCat\Status\PostCreation::fromText("  <b>Hello</b> @actor #Mixed_CASE\r\nhttps://alpha.example  ");
$assert($draft->body === "Hello @2 #mixed-case\nhttps://alpha.example", 'The shared draft cleans markup, line endings, mentions and tags.');
$assert($draft->tags === ['mixed-case'] && count($draft->links) === 1, 'Draft relations are extracted from the normalized stored text.');
$boundedDraft = \TinyCat\Status\PostCreation::fromText(str_repeat('x', 2000) . ' #outside');
$assert(mb_strlen($boundedDraft->body) === 2000 && $boundedDraft->tags === [], 'Tags outside the shared text limit cannot leave invisible relations.');
$image = ['path' => 'fixture.webp', 'width' => 10, 'height' => 20, 'bytes' => 100];
$beforeCreation = (int) $value('SELECT COUNT(*) FROM content');
$database->exec('CREATE TRIGGER fail_post_links BEFORE INSERT ON content_links BEGIN SELECT RAISE(ABORT, \'forced post failure\'); END');
try {
    \TinyCat\Status\PostCreation::create(1, $draft, $links, $image);
    $assert(false, 'The shared writer must propagate relation failures.');
} catch (PDOException) {
    $assert((int) $value('SELECT COUNT(*) FROM content') === $beforeCreation, 'A relation failure rolls back the newly created post.');
    $assert((int) $value('SELECT COUNT(*) FROM content_images') === 0, 'A relation failure rolls back the image record.');
    $assert((int) $value("SELECT COUNT(*) FROM terms WHERE name = 'mixed-case'") === 0, 'A relation failure rolls back new tags.');
}
$database->exec('DROP TRIGGER fail_post_links');
$imagePost = \TinyCat\Status\PostCreation::create(1, \TinyCat\Status\PostCreation::fromText(''), [], $image);
$assert((string) $value('SELECT published_at FROM content WHERE id = ' . $imagePost) <= \TinyCat\Support\Dates::dateDb(), 'The shared writer publishes image-only posts immediately.');
$assert((int) $value('SELECT COUNT(*) FROM content_images WHERE content_id = ' . $imagePost) === 1, 'An image-only post commits its image record.');
\TinyCat\Status\Posts::status_delete_content($imagePost, true, true, false);
foreach ([0, 1] as $authorId) {
    try {
        \TinyCat\Status\PostCreation::create($authorId, \TinyCat\Status\PostCreation::fromText($authorId === 0 ? 'Text' : ' '), []);
        $assert(false, 'The shared writer rejects missing authors and empty posts.');
    } catch (InvalidArgumentException) {
        $assert((int) $value('SELECT COUNT(*) FROM content') === $beforeCreation, 'Invalid drafts cannot create a partial post.');
    }
}

$database->exec('CREATE TABLE bot_sources (id INTEGER PRIMARY KEY, last_imported_at TEXT, last_error TEXT)');
$database->exec('CREATE TABLE bot_feed_history (bot_user_id INTEGER, feed_hash TEXT, item_hash TEXT, created_at TEXT, UNIQUE (bot_user_id, feed_hash, item_hash))');
$database->exec('INSERT INTO bot_sources (id) VALUES (1)');
$persistFeed = new ReflectionMethod(\TinyCat\Bots\Importer::class, 'persistFeedPost');
$feedHash = hash('sha256', 'fixture-feed');
$itemHash = hash('sha256', 'fixture-item');
$beforePosts = (int) $value('SELECT COUNT(*) FROM content');
$database->exec('CREATE TRIGGER fail_bot_link BEFORE INSERT ON content_links BEGIN SELECT RAISE(ABORT, \'forced bot link failure\'); END');
try {
    $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Bot post @actor #ROLLBACK'), $links, 0, 1);
    $assert(false, 'Bot link storage errors must propagate.');
} catch (PDOException) {
    $assert(true, 'Bot link storage errors propagate.');
}
$assert((int) $value('SELECT COUNT(*) FROM content') === $beforePosts && (int) $value('SELECT COUNT(*) FROM bot_feed_history') === 0, 'A failed bot import rolls back both the post and duplicate claim.');
$assert($value('SELECT last_imported_at FROM bot_sources WHERE id = 1') === null, 'A failed import does not mark its source imported.');
$assert((int) $value("SELECT COUNT(*) FROM terms WHERE name = 'rollback'") === 0, 'A failed bot import rolls back new tags.');
$database->exec('DROP TRIGGER fail_bot_link');
$database->exec('CREATE TRIGGER fail_bot_history BEFORE INSERT ON bot_feed_history BEGIN SELECT RAISE(ABORT, \'forced history failure\'); END');
try {
    $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Bot post @actor #ROLLBACK'), $links, 0, 1);
    $assert(false, 'History storage failures must propagate.');
} catch (PDOException) {
    $assert((int) $value('SELECT COUNT(*) FROM content') === $beforePosts, 'History storage failures cannot leave an untracked post.');
}
$database->exec('DROP TRIGGER fail_bot_history');
$botPost = $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Bot post @actor #ROLLBACK'), $links, 0, 1);
$assert($botPost > 0 && (int) $value('SELECT COUNT(*) FROM bot_feed_history') === 1, 'A retry atomically commits the bot post and history.');
$assert((int) $value('SELECT COUNT(*) FROM content_links WHERE content_id = ' . $botPost) === 2, 'A successful bot import commits all prepared links.');
try {
    $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Duplicate bot post'), $links, 0, 1);
    $assert(false, 'A duplicate feed claim must abort the second post.');
} catch (PDOException) {
    $assert((int) $value('SELECT COUNT(*) FROM content') === $beforePosts + 1, 'A concurrent duplicate claim cannot leave a second post.');
}

// Feed validation must reject content without swallowing technical failures.
$database->exec('INSERT INTO bot_sources (id) VALUES (2)');
$database->exec('CREATE TABLE bot_source_runs (id INTEGER PRIMARY KEY, source_id INTEGER, status TEXT, started_at TEXT, items_seen INTEGER, error TEXT)');
$database->exec("INSERT INTO bot_source_runs (id, source_id, status) VALUES (1, 2, 'running')");
$database->exec('CREATE TABLE scheduler_runs (id INTEGER PRIMARY KEY, status TEXT, finished_at TEXT, duration_ms INTEGER, summary_json TEXT, error TEXT)');
$database->exec("INSERT INTO scheduler_runs (id, status) VALUES (1, 'running')");
$importItems = new ReflectionMethod(\TinyCat\Bots\Importer::class, 'importItems');
$source = ['id' => 2, 'bot_user_id' => 1, 'feed_url' => 'https://feed.example/rss', 'post_template' => '{{title}}'];
$items = [
    ['guid' => 'blocked', 'title' => 'https://blocked.example/article'],
    ['guid' => 'long-tag', 'title' => '#' . str_repeat('a', 33)],
    ['guid' => 'empty', 'title' => '<b></b>'],
    ['guid' => 'allowed', 'title' => 'Allowed item #feed'],
];
$result = $importItems->invoke(null, $source, $items, 1);
$assert($result['status'] === 'posted', 'Invalid feed items do not prevent a later valid item from being posted.');
$assert(array_column($result['skipped_items'], 'code') === ['blocked_url', 'status_tag_too_long', 'status_required'], 'Feed skips preserve structured content rejection codes.');
$assert($result['skipped_items'][0]['params'] === ['host' => 'blocked.example'] && $result['skipped_items'][1]['params'] === ['max' => '32'], 'Feed rejections preserve the same parameters as HTTP validation.');
$sourceHash = \TinyCat\Bots\Importer::feedSourceHash($source['feed_url']);
$history = \TinyCat\Database\Connection::all('SELECT item_hash FROM bot_feed_history WHERE feed_hash = ?', [$sourceHash]);
$assert(array_column($history, 'item_hash') === [hash('sha256', 'allowed')], 'Rejected feed items never acquire successful duplicate claims.');
$scheduledResult = (new ReflectionMethod(\TinyCat\Bots\Importer::class, 'scheduledResult'))->invoke(null, [$result]);
\TinyCat\Scheduler\History::finish(1, $scheduledResult, 1);
$assert($scheduledResult['count'] === 1 && $scheduledResult['changed'] === 1, 'Feed task counters distinguish checked sources from newly published posts.');
$summary = json_decode((string) $value('SELECT summary_json FROM scheduler_runs WHERE id = 1'), true);
$assert(count($summary['skipped_items']) === 3 && $summary['skipped_items'][0]['source_id'] === 2, 'Scheduler history durably records each skipped item and its source.');
$assert($summary['skipped_items'][0]['item_hash'] === hash('sha256', 'blocked'), 'Skipped diagnostics identify the exact feed item without storing its content.');
$again = $importItems->invoke(null, $source, $items, 1);
$assert($again['status'] === 'current' && count($again['skipped_items']) === 3, 'All-invalid remaining items finish successfully and remain eligible for re-evaluation.');
try {
    \TinyCat\Status\PostCreation::create(1, \TinyCat\Status\PostCreation::fromText($items[0]['title']), []);
    $assert(false, 'Rejected content cannot bypass validation through the shared writer.');
} catch (InvalidArgumentException) {
    $assert(true, 'The shared writer refuses content rejected during preparation.');
}
$relaxed = $config->getValue();
$relaxed['moderation']['blocked_urls'] = '';
$config->setValue(null, $relaxed);
// Fresh fixture metadata prevents a remote preview fetch when the URL is admitted.
$admittedLink = \TinyCat\Status\LinkParser::fromRaw($items[0]['title']);
$insertLink->execute([$admittedLink['normalized_url'], $admittedLink['url_hash'], 'web', 'link', 'Cached article', '', '', '', $now, $now]);
$afterPolicyChange = $importItems->invoke(null, $source, [$items[0]], 1);
$assert($afterPolicyChange['status'] === 'posted' && $afterPolicyChange['skipped_items'] === [], 'Removing a URL block admits the original previously rejected item.');
// A changed template can repair previously rejected content without losing its GUID.
$repaired = $source;
$repaired['post_template'] = 'Repaired item';
$retry = $importItems->invoke(null, $repaired, [$items[1]], 1);
$assert($retry['status'] === 'posted' && $retry['skipped_items'] === [], 'A repaired template can publish a previously rejected feed item.');
$technical = [['guid' => 'technical', 'title' => 'Technical retry #retry'], ['guid' => 'after-error', 'title' => 'Must wait']];
$database->exec('CREATE TRIGGER fail_import_post BEFORE INSERT ON content BEGIN SELECT RAISE(ABORT, \'forced import failure\'); END');
$failed = $importItems->invoke(null, $source, $technical, 1);
$assert($failed['status'] === 'error' && $failed['skipped_items'] === [], 'Database failures remain technical errors, not content skips.');
$assert((int) $value("SELECT COUNT(*) FROM content WHERE body = 'Must wait'") === 0, 'A technical error stops the run before subsequent items.');
$assert((int) $value("SELECT COUNT(*) FROM bot_feed_history WHERE item_hash = '" . hash('sha256', 'technical') . "'") === 0, 'A technical failure leaves its feed item available for retry.');
$database->exec('DROP TRIGGER fail_import_post');
$retried = $importItems->invoke(null, $source, $technical, 1);
$assert($retried['status'] === 'posted', 'A technical failure can be retried successfully.');

$_GET = [];
$_POST = [];
$_FILES = [];
$_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/status/react';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$actor = ['id' => 2, 'username' => 'actor', 'role' => 'user', 'created_at' => '2025-01-01 00:00:00'];
$reaction = \TinyCat\Status\Actions::status_json_react(300, $actor);
$assert((bool) ($reaction['status']['liked'] ?? false), 'A status reaction is added.');
$reaction = \TinyCat\Status\Actions::status_json_react(300, $actor);
$assert(!(bool) ($reaction['status']['liked'] ?? true), 'A status reaction is removed.');

$reaction = \TinyCat\Status\Actions::status_json_react(200, $actor);
$assert((bool) ($reaction['status']['liked'] ?? false), 'A status-owner notification is created for a new reaction.');
$database->exec("UPDATE notifications SET read_at = '2026-08-14 12:00:00' WHERE content_id = 200");
$reaction = \TinyCat\Status\Actions::status_json_react(200, $actor);
$assert(!(bool) ($reaction['status']['liked'] ?? true), 'A notified reaction can be removed.');
$reaction = \TinyCat\Status\Actions::status_json_react(200, $actor);
$assert((bool) ($reaction['status']['liked'] ?? false), 'A removed reaction can be added again.');
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE content_id = 200') === 1, 'Repeated reactions reuse one notification.');
$assert($value('SELECT read_at FROM notifications WHERE content_id = 200') === null, 'A repeated reaction restores the notification as unread.');
$database->exec('DELETE FROM notifications WHERE content_id = 200');

$setComment = static function (string $body) : void {
    $_POST = ['comment' => $body];
    \TinyCat\Http\Request::reset();
};
$setComment('Root comment #nested');
$rootComment = \TinyCat\Status\Actions::status_json_comment(300, 0, $actor);
$rootCommentId = (int) ($rootComment['comment_id'] ?? 0);
$setComment('First reply');
$firstReply = \TinyCat\Status\Actions::status_json_comment(300, $rootCommentId, $actor);
$firstReplyId = (int) ($firstReply['comment_id'] ?? 0);
$setComment('Nested reply');
$nestedReply = \TinyCat\Status\Actions::status_json_comment(300, $firstReplyId, $actor);
$nestedReplyId = (int) ($nestedReply['comment_id'] ?? 0);
$assert($rootCommentId > 0 && $firstReplyId > 0 && $nestedReplyId > 0, 'Comment creation persists a root and replies.');
$assert((int) $value("SELECT parent_id FROM content_comments WHERE id = {$nestedReplyId}") === $rootCommentId, 'Replies deeper than one level attach to the root comment.');
$commentReaction = \TinyCat\Status\Actions::status_json_comment_like($rootCommentId, $actor);
$assert((bool) ($commentReaction['comment_like']['liked'] ?? false), 'A comment reaction is added.');
$commentReaction = \TinyCat\Status\Actions::status_json_comment_like($rootCommentId, $actor);
$assert(!(bool) ($commentReaction['comment_like']['liked'] ?? true), 'A comment reaction is removed.');

$_POST = ['body' => 'Updated status ' . $tagText];
\TinyCat\Http\Request::reset();
$updatedStatus = \TinyCat\Status\Actions::status_json_update(300, $actor);
$assert((int) ($updatedStatus['status_id'] ?? 0) === 300, 'Status editing keeps the existing aggregate identity.');
$assert((int) $value('SELECT COUNT(*) FROM content_tags WHERE content_id = 300') === 12, 'Status editing synchronizes more than ten tags atomically.');

$_POST = ['body' => 'Bot post @actor #ROLLBACK'];
\TinyCat\Http\Request::reset();
$createdStatus = \TinyCat\Status\Actions::status_json_create($actor);
$createdStatusId = (int) ($createdStatus['status_id'] ?? 0);
$assert($createdStatusId > 300 && (int) $value("SELECT COUNT(*) FROM content WHERE id = {$createdStatusId}") === 1, 'Status creation commits the aggregate.');
$assert((int) $value("SELECT COUNT(*) FROM content_tags WHERE content_id = {$createdStatusId}") === 1, 'Status creation synchronizes tag relations.');
$assert($value('SELECT body FROM content WHERE id = ' . $createdStatusId) === $value('SELECT body FROM content WHERE id = ' . $botPost), 'User and bot creation store identical normalized text for identical input.');
$assert($value('SELECT body FROM content WHERE id = ' . $botPost) === 'Bot post @2 #rollback', 'Bot creation uses canonical stored mentions and tags.');
$deletedStatus = \TinyCat\Status\Actions::status_json_delete($createdStatusId, $actor);
$assert((int) ($deletedStatus['deleted_status_id'] ?? 0) === $createdStatusId, 'Status deletion returns the deleted aggregate identity.');
$assert((int) $value("SELECT COUNT(*) FROM content WHERE id = {$createdStatusId}") === 0, 'Status deletion removes the created aggregate.');

$_POST = ['body' => 'Immediate publication @owner', 'schedule_post' => '1', 'scheduled_at' => '2099-01-01T12:00'];
\TinyCat\Http\Request::reset();
$createdAt = \TinyCat\Support\Dates::dateDb();
$immediate = \TinyCat\Status\Actions::status_json_create($actor);
$immediateId = (int) $immediate['status_id'];
$storedAt = (string) $value('SELECT published_at FROM content WHERE id = ' . $immediateId);
$assert($storedAt >= $createdAt && $storedAt <= \TinyCat\Support\Dates::dateDb(), 'Obsolete scheduling inputs cannot set a future publication date.');
$assert($immediate['code'] === 'status_created' && !array_key_exists('scheduled', $immediate['item']), 'Creation returns a published post without scheduling metadata.');
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE content_id = ' . $immediateId . ' AND user_id = 1') === 1, 'Immediate user publication delivers mention notifications.');
$database->exec("INSERT INTO users (id, username, role, bio, password, status) VALUES (3, 'feedbot', 'bot', '', '', 'active')");
$botSource = $source;
$botSource['bot_user_id'] = 3;
$botPublication = $importItems->invoke(null, $botSource, [['guid' => 'bot-mention', 'title' => 'Bot mention @owner']], 1);
$botPublicationId = (int) $botPublication['content_id'];
$assert((string) $value('SELECT published_at FROM content WHERE id = ' . $botPublicationId) <= \TinyCat\Support\Dates::dateDb(), 'Bot imports publish immediately through the shared writer.');
\TinyCat\Notifications\Delivery::notifyMentions('@1', ['id' => 3, 'role' => 'bot'], $botPublicationId);
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE content_id = ' . $botPublicationId) === 0, 'Bot imports and direct bot mention delivery do not send notifications.');
$assert(\TinyCat\Status\PostCreation::sendsMentionNotifications('admin') && !\TinyCat\Status\PostCreation::sendsMentionNotifications('bot'), 'Mention sender policy distinguishes human accounts from bots.');
$_POST = ['body' => 'Admin edited bot post @actor'];
\TinyCat\Http\Request::reset();
\TinyCat\Status\Actions::status_json_update($botPublicationId, ['id' => 1, 'username' => 'owner', 'role' => 'admin']);
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE content_id = ' . $botPublicationId) === 0, 'An administrator editing a bot post cannot bypass its mention policy.');
$database->exec('DELETE FROM notifications');

\TinyCat\Users\Following::author_follow(2, 1);
$assert(\TinyCat\Users\Following::author_is_followed(2, 1), 'Following creates the social relation.');
$assert((int) $value("SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND notification_key = 'follow:1:2'") === 1, 'Following creates one notification for the followed profile.');
$assert(\TinyCat\Notifications\Inbox::targetUrl(['type' => 'follow', 'actor_id' => 2]) === \TinyCat\Users\Profiles::author_url(2), 'A follow notification links to the follower profile.');
\TinyCat\Users\Profiles::author_unfollow(2, 1);
$assert(!\TinyCat\Users\Following::author_is_followed(2, 1), 'Unfollowing removes the social relation.');
\TinyCat\Users\Following::author_follow(2, 1);
$assert(\TinyCat\Users\Following::author_is_followed(2, 1), 'Following can be restored after an unfollow.');
$assert((int) $value("SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND notification_key = 'follow:1:2'") === 1, 'Repeated follows reuse one notification and do not resend email.');
$database->exec("DELETE FROM notifications WHERE user_id = 1 AND notification_key = 'follow:1:2'");

$database->exec("INSERT INTO content_comments (id, content_id, parent_id, user_id, body) VALUES (10, 200, NULL, 2, 'parent'), (11, 200, 10, 3, 'reply'), (20, 100, NULL, 2, 'status comment')");
$database->exec('INSERT INTO comment_likes (comment_id, user_id) VALUES (10, 4), (11, 4), (20, 4)');
$database->exec("INSERT INTO notifications (id, user_id, content_id, comment_id, type, notification_key, created_at, updated_at) VALUES (1, 1, 200, 10, 'content_comment', 'test:1', '$now', '$now'), (2, 1, 200, 11, 'content_comment', 'test:2', '$now', '$now'), (3, 1, 100, 20, 'content_comment', 'test:3', '$now', '$now'), (4, 1, 100, NULL, 'content_like', 'test:4', '$now', '$now')");
$database->exec('CREATE TRIGGER fail_comment_delete BEFORE DELETE ON content_comments WHEN OLD.id = 10 BEGIN SELECT RAISE(ABORT, \'forced comment failure\'); END');
$commentFailed = false;
try {
    \TinyCat\Status\Comments::status_delete_comment_rows(10);
} catch (PDOException) {
    $commentFailed = true;
}
$assert($commentFailed, 'A comment aggregate failure is propagated.');
$assert((int) $value('SELECT COUNT(*) FROM content_comments WHERE content_id = 200') === 2, 'Failed comment deletion restores parent and reply.');
$assert((int) $value('SELECT COUNT(*) FROM comment_likes WHERE comment_id IN (10, 11)') === 2, 'Failed comment deletion restores reactions.');
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE comment_id IN (10, 11)') === 2, 'Failed comment deletion restores notifications.');
$database->exec('DROP TRIGGER fail_comment_delete');
\TinyCat\Status\Comments::status_delete_comment_rows(10);
$assert((int) $value('SELECT COUNT(*) FROM content_comments WHERE content_id = 200') === 0, 'Successful comment deletion removes parent and reply.');
$assert((int) $value('SELECT COUNT(*) FROM comment_likes WHERE comment_id IN (10, 11)') === 0, 'Successful comment deletion removes reactions.');
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE comment_id IN (10, 11)') === 0, 'Successful comment deletion removes notifications.');

$database->exec("INSERT INTO content_likes (content_id, user_id) VALUES (100, 2)");
$database->exec("INSERT INTO content_images (content_id, path) VALUES (100, 'status.webp')");
$database->exec("INSERT INTO content_reports (id, content_id, reporter_id, status) VALUES (1, 100, 2, 'open')");
$database->exec('CREATE TRIGGER fail_content_delete BEFORE DELETE ON content WHEN OLD.id = 100 BEGIN SELECT RAISE(ABORT, \'forced content failure\'); END');
$contentFailed = false;
try {
    \TinyCat\Status\Posts::status_delete_content(100, true, true, false);
} catch (PDOException) {
    $contentFailed = true;
}
$assert($contentFailed, 'A status aggregate failure is propagated.');
foreach (['content', 'content_likes', 'content_comments', 'comment_likes', 'content_tags', 'content_links', 'content_images', 'content_reports'] as $table) {
    $column = in_array($table, ['comment_likes'], true) ? 'comment_id' : (in_array($table, ['content'], true) ? 'id' : 'content_id');
    $expected = $table === 'content_comments' || $table === 'comment_likes' ? 1 : ($table === 'content_links' ? 2 : ($table === 'content_tags' ? 12 : 1));
    $assert((int) $value("SELECT COUNT(*) FROM {$table} WHERE {$column} " . ($table === 'comment_likes' ? '= 20' : '= 100')) === $expected, "Failed status deletion restores {$table}.");
}
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE content_id = 100') === 2, 'Failed status deletion restores notifications.');
$database->exec('DROP TRIGGER fail_content_delete');
$imagePath = \TinyCat\Status\Posts::status_delete_content(100, true, true, false);
$assert($imagePath === 'status.webp', 'Status deletion returns the external file cleanup path.');
foreach (['content', 'content_likes', 'content_comments', 'comment_likes', 'content_tags', 'content_links', 'content_images', 'content_reports', 'notifications'] as $table) {
    $column = $table === 'comment_likes' ? 'comment_id' : ($table === 'content' ? 'id' : 'content_id');
    $where = $table === 'comment_likes' ? '= 20' : '= 100';
    $assert((int) $value("SELECT COUNT(*) FROM {$table} WHERE {$column} {$where}") === 0, "Successful status deletion removes {$table}.");
}

$owner = ['id' => 1, 'role' => 'user'];
$other = ['id' => 2, 'role' => 'user'];
$admin = ['id' => 9, 'role' => 'admin'];
$assert(\TinyCat\Status\Posts::status_can_edit(['author_id' => 1], $owner), 'A status owner may edit an unlocked status.');
$assert(!\TinyCat\Status\Posts::status_can_edit(['author_id' => 1, 'edit_locked_at' => $now], $owner), 'A moderation lock blocks owner edits.');
$assert(\TinyCat\Status\Posts::status_can_edit(['author_id' => 1, 'edit_locked_at' => $now], $admin), 'An administrator may edit a locked status.');
$assert(!\TinyCat\Status\Posts::status_can_delete(['author_id' => 1], $other), 'Another user may not delete a status.');
$assert(\TinyCat\Status\Posts::status_can_delete(['author_id' => 1], $admin), 'An administrator may delete a status.');
$assert(\TinyCat\Status\Comments::status_comment_can_edit(['user_id' => 2], $other), 'A comment author may edit the comment.');
$assert(!\TinyCat\Status\Comments::status_comment_can_edit(['user_id' => 2], $owner), 'The status owner may not rewrite another user comment.');
$assert(\TinyCat\Status\Comments::status_comment_can_delete(['user_id' => 2, 'content_id' => 200], $owner), 'The status owner may delete a comment on the status.');

$unique = null;
try {
    $database->exec("INSERT INTO terms (name) VALUES ('duplicate'), ('duplicate')");
} catch (PDOException $exception) {
    $unique = $exception;
}
$assert($unique instanceof PDOException && \TinyCat\Database\Connection::uniqueViolation($unique), 'Unique constraint races are recognized narrowly.');
$notNull = null;
try {
    $database->exec('INSERT INTO terms (name) VALUES (NULL)');
} catch (PDOException $exception) {
    $notNull = $exception;
}
$assert($notNull instanceof PDOException && !\TinyCat\Database\Connection::uniqueViolation($notNull), 'Non-unique constraint failures are not swallowed.');

$api = (string) file_get_contents($root . '/App/Status/Api.php');
$transport = (string) file_get_contents($root . '/App/Http/Api.php');
$intervalOffset = strpos($api, 'Actions::status_json_require_session_interval($action);');
$dispatchOffset = strpos($api, '$data = $operation();');
$assert(
    str_contains($transport, 'Middleware::authorize($access, $method !== \'GET\', $user);') && $intervalOffset !== false && $dispatchOffset > $intervalOffset,
    'Every status mutation retains auth, CSRF and interval checks before dispatch.'
);

$moderation = (string) file_get_contents($root . '/App/Moderation/Reports.php');
$assert(
    str_contains($moderation, "\$decision !== 'remove'"),
    'Removal notifications drop the foreign-key target before content deletion.'
);

try {
    \TinyCat\Database\Transactions::run(static fn (): array => \TinyCat\Status\Links::prepareLinks([]));
    $assert(false, 'Metadata preparation must reject an active transaction.');
} catch (LogicException) {
    $assert(true, 'Metadata preparation rejects an active transaction.');
}

$database->beginTransaction();
try {
    \TinyCat\Users\Avatar::replace(1, null, false, static fn (array $user): array => $user);
    $assert(false, 'Avatar replacement must reject an outer transaction.');
} catch (LogicException) {
    $assert($database->inTransaction(), 'Rejecting nested avatar replacement must not roll back the caller transaction.');
} finally {
    if ($database->inTransaction()) { $database->rollBack(); }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS write-path policy and transactional integrity (' . $checks . " checks)\n";
