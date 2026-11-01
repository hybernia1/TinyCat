<?php
declare(strict_types=1);

namespace TinyCat\Http {
    function fopen(string $url, string $mode, bool $includePath = false, mixed $context = null): mixed
    {
        if (!isset($GLOBALS['sourceImageFixtures'][$url])) { return \fopen($url, $mode, $includePath, $context); }
        $GLOBALS['sourceImageRequests'][] = $url;
        $stream = \fopen('php://temp', 'w+');
        fwrite($stream, $GLOBALS['sourceImageFixtures'][$url]);
        rewind($stream);
        $GLOBALS['sourceImageStreams'][(int) $stream] = true;
        return $stream;
    }
    function stream_get_meta_data(mixed $stream): array
    {
        $meta = \stream_get_meta_data($stream);
        return isset($GLOBALS['sourceImageStreams'][(int) $stream])
            ? array_replace($meta, ['wrapper_data' => $GLOBALS['sourceImageHeaders'] ?? ['HTTP/1.1 200 OK', 'Content-Type: image/png']]) : $meta;
    }
    function function_exists(string $name): bool
    {
        return $name !== 'curl_init' && \function_exists($name);
    }
}
namespace {

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
    'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, email TEXT, email_notifications INTEGER NOT NULL DEFAULT 0, locale TEXT, theme TEXT, avatar_exists INTEGER DEFAULT 0, avatar_appearance TEXT, updated_at TEXT, role TEXT NOT NULL, bio TEXT NOT NULL, password TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT, muted_until TEXT)',
    "CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT NOT NULL DEFAULT '', author_id INTEGER NOT NULL, published_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00', status TEXT NOT NULL DEFAULT 'published')",
    'CREATE TABLE content_likes (content_id INTEGER NOT NULL, user_id INTEGER NOT NULL)',
    'CREATE TABLE content_comments (id INTEGER PRIMARY KEY, content_id INTEGER NOT NULL, parent_id INTEGER, user_id INTEGER NOT NULL, body TEXT, created_at TEXT)',
    'CREATE TABLE comment_likes (comment_id INTEGER NOT NULL, user_id INTEGER NOT NULL)',
    'CREATE TABLE terms (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)',
    'CREATE TABLE content_tags (content_id INTEGER NOT NULL, term_id INTEGER NOT NULL, UNIQUE (content_id, term_id))',
    'CREATE TABLE links (id INTEGER PRIMARY KEY AUTOINCREMENT, normalized_url TEXT NOT NULL, url_hash TEXT NOT NULL UNIQUE, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, audio_url TEXT, created_at TEXT, updated_at TEXT)',
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
$insertLink->execute(['https://alpha.example', hash('sha256', 'https://alpha.example'), 'web', 'link', 'Alpha title', '', '', '', $now, $now]);
$insertLink->execute(['https://beta.example', hash('sha256', 'https://beta.example'), 'web', 'link', 'Beta title', '', '', '', $now, $now]);
$database->exec("INSERT INTO content_links (content_id, link_id, created_at) VALUES (100, 1, '$now')");
$database->exec('CREATE TRIGGER fail_link_attach BEFORE INSERT ON content_links WHEN NEW.link_id = 3 BEGIN SELECT RAISE(ABORT, \'forced link failure\'); END');
$links = [
    ['normalized_url' => 'https://alpha.example', 'url_hash' => hash('sha256', 'https://alpha.example')],
    ['normalized_url' => 'https://beta.example', 'url_hash' => hash('sha256', 'https://beta.example')],
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

$database->exec('CREATE TABLE bot_sources (id INTEGER PRIMARY KEY, last_imported_at TEXT, last_error TEXT, bot_user_id INTEGER DEFAULT 1, feed_hash TEXT, enabled INTEGER DEFAULT 1)');
$database->exec('PRAGMA foreign_keys = ON');
$database->exec('CREATE TABLE bot_source_posts (content_id INTEGER PRIMARY KEY REFERENCES content(id) ON DELETE CASCADE, source_id INTEGER NOT NULL)');
$database->exec('CREATE TABLE bot_feed_history (bot_user_id INTEGER, feed_hash TEXT, item_hash TEXT, created_at TEXT, UNIQUE (bot_user_id, feed_hash, item_hash))');
$database->exec('INSERT INTO bot_sources (id) VALUES (1)');
$persistFeed = new ReflectionMethod(\TinyCat\Bots\Importer::class, 'persistFeedPost');
$feedHash = hash('sha256', 'fixture-feed');
$database->prepare('UPDATE bot_sources SET feed_hash = ? WHERE id = 1')->execute([$feedHash]);
$itemHash = hash('sha256', 'fixture-item');
$beforePosts = (int) $value('SELECT COUNT(*) FROM content');
$database->exec('CREATE TRIGGER fail_bot_link BEFORE INSERT ON content_links BEGIN SELECT RAISE(ABORT, \'forced bot link failure\'); END');
try {
    $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Bot post @actor #ROLLBACK'), $links);
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
    $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Bot post @actor #ROLLBACK'), $links);
    $assert(false, 'History storage failures must propagate.');
} catch (PDOException) {
    $assert((int) $value('SELECT COUNT(*) FROM content') === $beforePosts, 'History storage failures cannot leave an untracked post.');
}
$database->exec('DROP TRIGGER fail_bot_history');
$botPost = $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Bot post @actor #ROLLBACK'), $links);
$assert($botPost > 0 && (int) $value('SELECT COUNT(*) FROM bot_feed_history') === 1, 'A retry atomically commits the bot post and history.');
$assert((int) $value('SELECT COUNT(*) FROM content_links WHERE content_id = ' . $botPost) === 2, 'A successful bot import commits all prepared links.');
try {
    $persistFeed->invoke(null, 1, 1, $feedHash, $itemHash, \TinyCat\Status\PostCreation::fromText('Duplicate bot post'), $links);
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
$database->prepare('UPDATE bot_sources SET feed_hash = ? WHERE id = 2')->execute([\TinyCat\Bots\Importer::feedSourceHash($source['feed_url'])]);
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

// Missing legacy settings, batch imports, duplicate GUIDs and queue continuation.
$database->exec('INSERT INTO bot_sources (id) VALUES (3), (4)');
$queueSource = array_replace($source, ['id' => 3, 'bot_user_id' => 900, 'feed_url' => 'https://queue.example/rss']);
$database->prepare('UPDATE bot_sources SET bot_user_id = 900, feed_hash = ? WHERE id = 3')->execute([\TinyCat\Bots\Importer::feedSourceHash($queueSource['feed_url'])]);
$database->prepare('UPDATE bot_sources SET bot_user_id = 900, feed_hash = ? WHERE id = 4')->execute([\TinyCat\Bots\Importer::feedSourceHash('https://queue.example/other')]);
foreach ([[], ['publication_interval_minutes' => null], ['publication_interval_minutes' => 0]] as $legacy) {
    $assert(\TinyCat\Bots\Importer::publicationInterval($legacy) === 30, 'Unset legacy publication intervals default to 30 minutes.');
}
$batch = $importItems->invoke(null, $queueSource, [
    ['guid' => 'queue-a', 'title' => 'Queue first'],
    ['guid' => 'queue-b', 'title' => 'Queue second'],
    ['guid' => 'queue-b', 'title' => 'Repeated GUID'],
    ['guid' => 'queue-c', 'title' => 'Queue third'],
], 1);
$queueRows = \TinyCat\Database\Connection::all('SELECT * FROM content WHERE author_id = 900 ORDER BY published_at, id');
$assert($batch['status'] === 'posted' && $batch['imported_count'] === 3 && count($queueRows) === 3, 'One run saves every new valid item and suppresses repeated GUIDs.');
$assert(strtotime($queueRows[1]['published_at']) - strtotime($queueRows[0]['published_at']) === 1800
    && strtotime($queueRows[2]['published_at']) - strtotime($queueRows[1]['published_at']) === 1800, 'Default publication slots are 30 minutes apart.');
$assert(\TinyCat\Status\Posts::status_can_view($queueRows[0]) && !\TinyCat\Status\Posts::status_can_view($queueRows[1]), 'The first slot is public immediately and later slots remain hidden.');
$batchSummary = (new ReflectionMethod(\TinyCat\Bots\Importer::class, 'scheduledResult'))->invoke(null, [$batch]);
$assert($batchSummary['changed'] === 3, 'Scheduler change counts include every imported post.');
$queueSource['publication_interval_minutes'] = 45;
$continuation = $importItems->invoke(null, $queueSource, [['guid' => 'queue-c', 'title' => 'Queue third'], ['guid' => 'queue-d', 'title' => 'Queue fourth']], 1);
$continuedAt = (string) $value('SELECT published_at FROM content WHERE id = ' . $continuation['content_id']);
$assert($continuation['imported_count'] === 1 && strtotime($continuedAt) - strtotime($queueRows[2]['published_at']) === 2700, 'Later imports append after the existing queue using the newly configured interval.');
$otherSource = array_replace($queueSource, ['id' => 4, 'feed_url' => 'https://queue.example/other']);
$otherBatch = $importItems->invoke(null, $otherSource, [['guid' => 'other-source', 'title' => 'Other source']], 1);
$otherAt = (string) $value('SELECT published_at FROM content WHERE id = ' . $otherBatch['content_id']);
$assert(abs(strtotime($otherAt) - time()) < 5 && $otherAt < $continuedAt, 'A second source starts its own timeline independently of the same bot account.');
$database->exec("CREATE TRIGGER fail_second_queue_item BEFORE INSERT ON content WHEN NEW.body = 'Queue failure' BEGIN SELECT RAISE(ABORT, 'forced second item failure'); END");
$partialItems = [['guid' => 'queue-e', 'title' => 'Before failure'], ['guid' => 'queue-f', 'title' => 'Queue failure'], ['guid' => 'queue-g', 'title' => 'After failure']];
$partial = $importItems->invoke(null, $queueSource, $partialItems, 1);
$assert($partial['status'] === 'error' && $partial['imported_count'] === 1, 'A partial failure reports the posts already committed.');
$database->exec('DROP TRIGGER fail_second_queue_item');
$resumed = $importItems->invoke(null, $queueSource, $partialItems, 1);
$assert($resumed['imported_count'] === 2 && (int) $value('SELECT COUNT(*) FROM content WHERE author_id = 900') === 8, 'Retrying a partial batch imports only the remaining items.');
$database->exec('DELETE FROM content WHERE author_id = 900');

// RSS metadata, URL normalization and single-card rendering must share the same path.
require __DIR__ . '/bots/image-cases.php';
$feedPreviewDirectory = $root . '/uploads/links/' . date('Y/m');
if (!is_dir($feedPreviewDirectory)) { mkdir($feedPreviewDirectory, 0775, true); }
$feedPreviewUrl = '/uploads/links/' . date('Y/m') . '/' . bin2hex(random_bytes(20)) . '.webp';
$feedPreviewPath = $root . $feedPreviewUrl;
$preview = imagecreatetruecolor(184, 172);
imagewebp($preview, $feedPreviewPath);
imagedestroy($preview);
try {
    $videoLink = \TinyCat\Status\LinkParser::fromRaw('https://youtu.be/feed123?si=share');
    \TinyCat\Status\Links::status_link_upsert(array_replace($videoLink, ['title' => 'Cached feed video', 'image_url' => $feedPreviewUrl, '_metadata_updated_at' => $now]));
    $feedWithLinks = array_replace($source, ['post_template' => "{{title}}\n{{url}}\nhttps://youtu.be/feed123?si=share"]);
    $feedItem = ['guid' => 'normalized-image-card', 'title' => 'RSS article title', 'description' => 'RSS article summary',
        'url' => 'https://93.184.216.34/rss-article?utm_source=feed', 'image_urls' => [$feedPreviewUrl]];
    $feedResult = $importItems->invoke(null, $feedWithLinks, [$feedItem], 1);
    $assert($feedResult['status'] === 'posted', 'Bots publish posts with feed metadata and multiple provider links.');
    $feedPostId = (int) ($feedResult['content_id'] ?? 0);
    $feedRow = \TinyCat\Database\Connection::one('SELECT * FROM content WHERE id = ?', [$feedPostId]);
    $feedResource = \TinyCat\Status\Resource::post($feedRow, null);
    $assert(count($feedResource['links']) === 1 && $feedResource['links'][0]['title'] === 'RSS article title'
        && $feedResource['links'][0]['description'] === 'RSS article summary' && $feedResource['links'][0]['image_url'] === $feedPreviewUrl,
        'The first bot link retains RSS title, summary and local image without article scraping.');
    $assert($feedResource['links'][0]['url'] === 'https://93.184.216.34/rss-article'
        && in_array('https://www.youtube.com/watch?v=feed123', array_column($feedResource['segments'], 'url'), true)
        && (int) $value('SELECT COUNT(*) FROM content_links WHERE content_id = ' . $feedPostId) === 2,
        'Bots use canonical link identity, store both relations and render the second URL as a plain link.');
    $feedAgain = $importItems->invoke(null, $feedWithLinks, [$feedItem], 1);
    $assert($feedAgain['status'] === 'current', 'Canonical link changes do not bypass GUID-based bot history.');
} finally {
    if (is_file($feedPreviewPath)) { unlink($feedPreviewPath); }
}

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
$database->exec('INSERT INTO bot_sources (id) VALUES (5)');
$botSource['id'] = 5;
$database->prepare('UPDATE bot_sources SET bot_user_id = 3, feed_hash = ? WHERE id = 5')->execute([\TinyCat\Bots\Importer::feedSourceHash($botSource['feed_url'])]);
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

$assert(!\TinyCat\Status\Posts::status_can_view(['published_at' => '2020-01-01', 'status' => 'hidden']), 'Moderation-hidden posts cannot be viewed or interacted with.');
$assert(\TinyCat\Status\Posts::status_can_view(['published_at' => '2020-01-01', 'status' => 'published']), 'Dismissed reports keep published posts visible.');
$owner = ['id' => 1, 'role' => 'user'];
$other = ['id' => 2, 'role' => 'user'];
$admin = ['id' => 9, 'role' => 'admin'];
$assert(\TinyCat\Status\Posts::status_can_edit(['author_id' => 1], $owner), 'A status owner may edit a published status.');
$assert(\TinyCat\Status\Posts::status_can_edit(['author_id' => 1, 'status' => 'hidden'], $owner), 'A status owner may edit hidden content without publishing it.');
$assert(\TinyCat\Status\Posts::status_can_edit(['author_id' => 1, 'status' => 'hidden'], $admin), 'An administrator may edit hidden content.');
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
    'Hide notifications do not link to the unavailable public content.'
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

$database->exec("INSERT INTO links (id, normalized_url, url_hash, provider, link_type) VALUES (9001, 'https://unknown.example', 'taxonomy-unknown', 'web', 'link'), (9002, 'https://youtube.com/watch?v=abc123', 'taxonomy-video', 'youtube', 'video')");
$normalizeLinks = require $root . '/migrations/20261027_001_link_taxonomy.php';
$normalizeLinks($database);
$normalizeLinks($database);
$assert($value('SELECT provider FROM links WHERE id = 9001') === '' && $value('SELECT link_type FROM links WHERE id = 9001') === 'web', 'Classification migration normalizes legacy unknown links and is repeatable.');
$assert($value('SELECT provider FROM links WHERE id = 9002') === 'youtube' && $value('SELECT link_type FROM links WHERE id = 9002') === 'video', 'Classification migration preserves recognized providers, content types and row IDs.');
$wpLinkId = \TinyCat\Status\Links::status_link_upsert(['normalized_url' => 'https://wp.example/article', 'url_hash' => hash('sha256', 'wp-taxonomy'), 'provider' => 'wordpress', 'link_type' => 'web', 'title' => 'Article']);
$assert($value('SELECT provider FROM links WHERE id = ' . $wpLinkId) === 'wordpress' && $value('SELECT link_type FROM links WHERE id = ' . $wpLinkId) === 'web', 'Recognized WordPress links persist their platform separately from content type.');

$migrationDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrationDb->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, normalized_url TEXT NOT NULL, url_hash TEXT NOT NULL UNIQUE, title TEXT, description TEXT, image_url TEXT, video_id TEXT)');
$migrationDb->exec('CREATE TABLE content_links (content_id INTEGER, link_id INTEGER, created_at TEXT, PRIMARY KEY (content_id, link_id))');
$migrationInsert = $migrationDb->prepare('INSERT INTO links VALUES (?, ?, ?, ?, ?, ?, ?)');
$migrationInsert->execute([1, 'https://twitter.com/account/status/123?s=20', 'old-x', 'Account', '', '', '']);
$migrationInsert->execute([2, 'https://x.com/account/status/123/photo/1', 'old-photo', '', 'Caption', '/uploads/preview.webp', '']);
$migrationDb->exec("INSERT INTO content_links VALUES (10, 1, '2026-10-27'), (10, 2, '2026-10-27'), (20, 2, '2026-10-27')");
$normalizeStored = require $root . '/migrations/20261027_002_link_normalization.php';
$dryReport = $normalizeStored($migrationDb, false);
$assert($dryReport['changed'] === 2 && $dryReport['merged'] === 1 && (int) $migrationDb->query('SELECT COUNT(*) FROM links')->fetchColumn() === 2, 'Normalization audit reports duplicates without changing records.');
$migrationDb->exec("CREATE TRIGGER fail_normalization BEFORE UPDATE ON links BEGIN SELECT RAISE(ABORT, 'forced migration failure'); END");
try { $normalizeStored($migrationDb); } catch (PDOException) { }
$assert((int) $migrationDb->query('SELECT COUNT(*) FROM links')->fetchColumn() === 2 && (int) $migrationDb->query('SELECT COUNT(*) FROM content_links')->fetchColumn() === 3, 'Normalization failure restores deleted links and transferred relations.');
$migrationDb->exec('DROP TRIGGER fail_normalization');
$normalizeStored($migrationDb);
$migrated = $migrationDb->query('SELECT * FROM links')->fetch(PDO::FETCH_ASSOC);
$assert($migrated['normalized_url'] === 'https://x.com/i/web/status/123' && $migrated['url_hash'] === hash('sha256', $migrated['normalized_url']), 'Migration stores one canonical identity.');
$assert($migrated['title'] === 'Account' && $migrated['description'] === 'Caption' && $migrated['image_url'] === '/uploads/preview.webp', 'Duplicate merge preserves complementary cached metadata.');
$assert((int) $migrationDb->query('SELECT COUNT(*) FROM content_links WHERE link_id = 1')->fetchColumn() === 2 && (int) $migrationDb->query('SELECT COUNT(*) FROM links')->fetchColumn() === 1, 'Duplicate merge preserves both posts and collapses overlapping relations.');
$again = $normalizeStored($migrationDb);
$assert($again['changed'] === 0 && $again['merged'] === 0, 'Normalization migration is repeatable.');
$preparedDuplicates = \TinyCat\Status\Links::prepareLinks([
    ['normalized_url' => 'https://alpha.example?utm_source=one', 'url_hash' => 'stale-one'],
    ['normalized_url' => 'https://alpha.example?utm_source=two', 'url_hash' => 'stale-two'],
]);
$assert(count($preparedDuplicates) === 1 && $preparedDuplicates[0]['url_hash'] === hash('sha256', 'https://alpha.example'), 'Preparation normalizes and deduplicates identities before metadata lookup.');
$firstVariantId = \TinyCat\Status\Links::status_link_upsert(['normalized_url' => 'https://twitter.com/account/status/987?s=20', 'url_hash' => 'stale-first']);
$secondVariantId = \TinyCat\Status\Links::status_link_upsert(['normalized_url' => 'https://x.com/other/status/987/photo/1', 'url_hash' => 'stale-second']);
$assert($firstVariantId > 0 && $firstVariantId === $secondVariantId, 'Writing equivalent provider variants reuses one database record despite different supplied hashes.');

// Exercise the actual review transaction: evidence must survive a moderation decision.
$database->exec('ALTER TABLE users ADD COLUMN muted_by INTEGER');
$database->exec('ALTER TABLE users ADD COLUMN muted_reason TEXT');
foreach (['reviewed_at TEXT', 'reviewed_by INTEGER', 'action_note TEXT', 'reason TEXT', 'note TEXT', 'created_at TEXT'] as $column) {
    $database->exec('ALTER TABLE content_reports ADD COLUMN ' . $column);
}
\TinyCat\Users\Authentication::reset(null, true);
$database->exec("INSERT INTO content (id, author_id, body) VALUES (9500, 1, 'Preserved evidence')");
$database->exec("INSERT INTO content_images (content_id, path) VALUES (9500, '2026/01/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp')");
$database->exec("INSERT INTO content_reports (id, content_id, reporter_id, status, reason, note, created_at) VALUES (9500, 9500, 2, 'open', 'spam', 'First reason', '2026-01-01'), (9501, 9500, 2, 'open', 'abuse', 'Second reason', '2026-01-02')");
$database->exec("INSERT INTO content_comments (id, content_id, user_id, body, created_at) VALUES (9500, 9500, 2, 'Preserved discussion', '2026-01-01')");
$result = \TinyCat\Moderation\Reports::tc_admin_moderation_review_report(9500, 'remove');
$assert($result['status'] === 'resolved', 'Review resolves a group of reports.');
$assert($value('SELECT body FROM content WHERE id = 9500') === 'Preserved evidence', 'Review preserves the original post.');
$assert($value('SELECT path FROM content_images WHERE content_id = 9500') === '2026/01/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp', 'Review preserves image evidence.');
$assert((int) $value("SELECT COUNT(*) FROM content_reports WHERE content_id = 9500 AND status = 'resolved'") === 2, 'Review keeps and resolves every open report.');
$assert(!\TinyCat\Status\Posts::status_can_view(\TinyCat\Status\Posts::status_find(9500)), 'Review hides the preserved post publicly.');
$assert(count(\TinyCat\Moderation\Reports::detail(9500)['reports']) === 2, 'The administrator detail retains the complete report history after review.');
$preview = \TinyCat\Moderation\Reports::detail(9500)['preview'];
$assert($preview['item']['body'] === 'Preserved evidence' && $preview['item']['image']['url'] !== '', 'The hidden post preview retains the standard frontend post and image resource.');
$assert($preview['comments'][0]['body'] === 'Preserved discussion', 'The hidden post preview includes its preserved discussion.');
$longReportBody = str_repeat('Complete report context ', 30);
$assert(\TinyCat\Moderation\Resource::report(['body' => $longReportBody])['excerpt'] === trim($longReportBody), 'Report resources retain complete text for the shared visual table clamp and tooltip.');
$detail = \TinyCat\Moderation\Reports::detail(9500);
$assert($detail['content'] === ['id' => 9500, 'status' => 'hidden'], 'Moderation details expose only the necessary content state.');
$assert(str_contains($detail['reports'][0]['created_at'], 'T') && !array_key_exists('reviewed_by', $detail['reports'][0]), 'Report entries use canonical dates and omit raw database metadata.');
$database->exec("INSERT INTO notifications (id, user_id, actor_id, content_id, type, notification_key, created_at, updated_at) VALUES (9500, 2, 1, 9500, 'content_like', 'hidden-evidence-notification', '2026-01-01', '2026-01-01')");
$hiddenNotification = array_values(array_filter(\TinyCat\Notifications\Inbox::items(2), static fn (array $item): bool => (int) $item['id'] === 9500))[0];
$hiddenNotificationResource = \TinyCat\Notifications\Resource::item($hiddenNotification);
$assert($hiddenNotificationResource['excerpt'] === '' && $hiddenNotificationResource['url'] === '/notifications', 'Existing notifications do not expose hidden post text or broken post links.');
$assert(\TinyCat\Notifications\Inbox::open(9500, 2) === '/notifications', 'Opening an old notification respects current post visibility.');
$assert((int) $value('SELECT content_id FROM notifications WHERE id = 9500') === 9500, 'Hiding preserves the notification record and its original relationship.');

$database->exec("INSERT INTO content (id, author_id, body) VALUES (9502, 1, 'Kept evidence')");
$database->exec("INSERT INTO content_reports (id, content_id, reporter_id, status) VALUES (9502, 9502, 2, 'open')");
\TinyCat\Moderation\Reports::tc_admin_moderation_review_report(9502, 'dismiss');
$kept = \TinyCat\Status\Posts::status_find(9502);
$assert(\TinyCat\Status\Posts::status_can_view($kept) && \TinyCat\Status\Posts::status_can_edit($kept, $owner), 'Dismissal leaves the original post visible and editable.');
$assert($value('SELECT status FROM content_reports WHERE id = 9502') === 'dismissed', 'Dismissal remains in the report history.');

$_POST = ['reason' => 'spam', 'note' => 'A new report after dismissal'];
\TinyCat\Http\Request::reset();
\TinyCat\Status\Actions::status_json_report(9502, $other);
$reopenedId = (int) $value("SELECT id FROM content_reports WHERE content_id = 9502 AND status = 'open'");
$assert($reopenedId !== 9502 && $reopenedId > 0, 'The same reporter may submit a new report after dismissal.');
$assert($value('SELECT status FROM content WHERE id = 9502') === 'published', 'Reporting never hides a post.');
\TinyCat\Status\Actions::status_json_report(9502, $other);
$assert((int) $value("SELECT COUNT(*) FROM content_reports WHERE content_id = 9502 AND reporter_id = 2 AND status = 'open'") === 1, 'Repeated submissions update one open report instead of duplicating it.');
$assert($value('SELECT status FROM content_reports WHERE id = 9502') === 'dismissed', 'Re-reporting does not overwrite the earlier moderation decision.');
\TinyCat\Moderation\Reports::tc_admin_moderation_review_report($reopenedId, 'dismiss');
\TinyCat\Status\Actions::status_json_report(9502, $other);
$assert((int) $value('SELECT COUNT(*) FROM content_reports WHERE content_id = 9502') === 3, 'A later reporting cycle retains every closed report.');

$database->exec('UPDATE users SET muted_until = NULL WHERE id = 1');
$_POST = ['body' => 'Edited while hidden', 'status' => 'published'];
\TinyCat\Http\Request::reset();
$hiddenEdit = \TinyCat\Status\Actions::status_json_update(9500, $owner);
$assert($hiddenEdit['item']['body'] === 'Edited while hidden' && $hiddenEdit['item']['status'] === 'hidden', 'An authorized author can edit a hidden post without publishing it.');
$assert(!\TinyCat\Status\Posts::status_can_view(\TinyCat\Status\Posts::status_find(9500)), 'Editing does not undo the administrator visibility decision.');
\TinyCat\Moderation\Reports::publish(9500);
$assert(\TinyCat\Status\Posts::status_can_view(\TinyCat\Status\Posts::status_find(9500)), 'Explicit administrative publication makes hidden content visible again.');
$assert((int) $value("SELECT COUNT(*) FROM content_reports WHERE content_id = 9500 AND status = 'resolved'") === 2, 'Publication does not reopen or discard resolved reports.');
$_POST = ['reason' => 'abuse', 'note' => 'New evidence after administrative publication'];
\TinyCat\Http\Request::reset();
\TinyCat\Status\Actions::status_json_report(9500, $other);
$assert($value('SELECT status FROM content WHERE id = 9500') === 'published', 'A previously hidden and restored post is not hidden automatically by a new report.');
$assert((int) $value("SELECT COUNT(*) FROM content_reports WHERE content_id = 9500 AND status = 'resolved'") === 2 && (int) $value("SELECT COUNT(*) FROM content_reports WHERE content_id = 9500 AND status = 'open'") === 1, 'Re-reporting restored content appends an open report beside the earlier resolved history.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS write-path policy and transactional integrity (' . $checks . " checks)\n";

}
