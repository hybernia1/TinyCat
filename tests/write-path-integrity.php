<?php
declare(strict_types=1);

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
require_once $root . '/App/Core.php';
require_once $root . '/App/Captcha.php';
require_once $root . '/App/Cache.php';
require_once $root . '/App/Minifier.php';
require_once $root . '/App/Avatar.php';
require_once $root . '/App/StatusImage.php';
require_once $root . '/App/SiteIdentity.php';
require_once $root . '/App/StatusLinks.php';
require_once $root . '/App/LinkMetadata.php';
require_once $root . '/App/Notifications.php';
require_once $root . '/App/UserRoles.php';
require_once $root . '/App/functions.php';

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$booted = new ReflectionProperty(Core::class, 'booted');
$booted->setValue(null, true);
$config = new ReflectionProperty(Core::class, 'config');
$config->setValue(null, [
    'app' => ['url' => 'http://localhost'],
    'database' => ['driver' => 'sqlite'],
    'install' => ['complete' => true, 'locale' => 'en'],
    'i18n' => ['locale' => 'en'],
    'datetime' => ['timezone' => 'UTC'],
    'cache' => ['driver' => 'filesystem'],
]);
Core::setDb($database);

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
    'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, email TEXT, email_notifications INTEGER NOT NULL DEFAULT 0, locale TEXT, role TEXT NOT NULL, bio TEXT NOT NULL, password TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT, muted_until TEXT)',
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
    auth_recovery_replace_token(1, 'new-token', '2030-01-01 00:00:00');
} catch (PDOException) {
    $recoveryFailed = true;
}
$assert($recoveryFailed, 'A replacement-token failure is propagated.');
$assert($value('SELECT token_hash FROM password_reset_tokens WHERE user_id = 1') === 'old-token', 'Failed token replacement restores the previous token.');
$database->exec('DROP TRIGGER fail_recovery_token');
auth_recovery_replace_token(1, 'active-token', '2030-01-01 00:00:00');
$assert($value('SELECT token_hash FROM password_reset_tokens WHERE user_id = 1') === 'active-token', 'Successful token replacement commits one current token.');
$assert(
    auth_recovery_consume_token(1, 'active-token', 'first-password', '2026-08-09 12:00:00'),
    'A current recovery token is consumed atomically.'
);
$assert(
    !auth_recovery_consume_token(1, 'active-token', 'second-password', '2026-08-09 12:00:01'),
    'A consumed recovery token cannot be reused.'
);
$assert($value('SELECT password FROM users WHERE id = 1') === 'first-password', 'Token reuse cannot overwrite the new password.');

$tagText = implode(' ', array_map(static fn (int $id): string => '#tag' . $id, range(1, 12)));
$assert(count(status_tags_from_text($tagText)) === 12, 'Tag parsing does not truncate at ten unique tags.');
$assert(count(status_require_valid_tags($tagText)) === 12, 'Tag validation accepts more than ten unique tags.');
$assert(status_tag_normalize(str_repeat('a', 33)) === '', 'The storage-backed per-tag length bound remains.');

$youtube = StatusLinks::fromRaw('https://youtu.be/abc123');
$assert(is_array($youtube) && !array_key_exists('embed_url', $youtube), 'Video parser retains only the canonical video identifier.');
$assert(status_video_embed_url(['provider' => 'youtube', 'video_id' => 'abc123']) === 'https://www.youtube.com/embed/abc123', 'YouTube embed URL is derived from its canonical identifier.');
$assert(status_video_embed_url(['provider' => 'vimeo', 'video_id' => '987654']) === 'https://player.vimeo.com/video/987654', 'Vimeo embed URL is derived from its canonical identifier.');
$assert(status_video_embed_url(['provider' => 'dailymotion', 'video_id' => 'x9demo']) === 'https://www.dailymotion.com/embed/video/x9demo', 'Dailymotion embed URL is derived from its canonical identifier.');
$assert(status_video_embed_url(['provider' => 'unknown', 'video_id' => 'abc123']) === '', 'Unknown video providers cannot produce an embed URL.');
$assert(!array_key_exists('embed_url', status_link_data((array) $youtube)), 'Link persistence excludes the derived embed URL.');

$database->exec('INSERT INTO content (id, author_id) VALUES (100, 1), (200, 1), (300, 2)');
$database->exec("INSERT INTO terms (id, name) VALUES (1, 'old')");
$database->exec('INSERT INTO content_tags (content_id, term_id) VALUES (100, 1)');
$database->exec('CREATE TRIGGER fail_tag_attach BEFORE INSERT ON content_tags WHEN NEW.term_id = 3 BEGIN SELECT RAISE(ABORT, \'forced tag failure\'); END');
$tagFailed = false;
try {
    status_sync_tags(100, ['alpha', 'beta']);
} catch (PDOException) {
    $tagFailed = true;
}
$assert($tagFailed, 'A non-unique tag relation failure is propagated.');
$assert((int) $value('SELECT term_id FROM content_tags WHERE content_id = 100') === 1, 'Failed tag synchronization restores the old relation.');
$assert((int) $value('SELECT COUNT(*) FROM terms') === 1, 'Failed tag synchronization rolls back newly created terms.');
$database->exec('DROP TRIGGER fail_tag_attach');
status_sync_tags(100, status_tags_from_text($tagText));
$assert((int) $value('SELECT COUNT(*) FROM content_tags WHERE content_id = 100') === 12, 'Successful tag synchronization persists every valid tag.');

$now = date_db();
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
    status_sync_links(100, $links);
} catch (PDOException) {
    $linkFailed = true;
}
$assert($linkFailed, 'A non-unique link relation failure is propagated.');
$assert((int) $value('SELECT link_id FROM content_links WHERE content_id = 100') === 1, 'Failed link synchronization restores the old relation.');
$database->exec('DROP TRIGGER fail_link_attach');
status_sync_links(100, $links);
$assert((int) $value('SELECT COUNT(*) FROM content_links WHERE content_id = 100') === 2, 'Successful link synchronization replaces all relations.');

$_GET = [];
$_POST = [];
$_FILES = [];
$_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/status/react';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$actor = ['id' => 2, 'username' => 'actor', 'role' => 'user', 'created_at' => '2025-01-01 00:00:00'];
$reaction = status_json_react(300, $actor);
$assert((bool) ($reaction['status']['liked'] ?? false), 'A status reaction is added.');
$reaction = status_json_react(300, $actor);
$assert(!(bool) ($reaction['status']['liked'] ?? true), 'A status reaction is removed.');

$reaction = status_json_react(200, $actor);
$assert((bool) ($reaction['status']['liked'] ?? false), 'A status-owner notification is created for a new reaction.');
$database->exec("UPDATE notifications SET read_at = '2026-08-14 12:00:00' WHERE content_id = 200");
$reaction = status_json_react(200, $actor);
$assert(!(bool) ($reaction['status']['liked'] ?? true), 'A notified reaction can be removed.');
$reaction = status_json_react(200, $actor);
$assert((bool) ($reaction['status']['liked'] ?? false), 'A removed reaction can be added again.');
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE content_id = 200') === 1, 'Repeated reactions reuse one notification.');
$assert($value('SELECT read_at FROM notifications WHERE content_id = 200') === null, 'A repeated reaction restores the notification as unread.');
$database->exec('DELETE FROM notifications WHERE content_id = 200');

$payload = new ReflectionProperty(Core::class, 'payload');
$setComment = static function (string $body) use ($payload): void {
    $_POST = ['comment' => $body];
    $payload->setValue(null, null);
};
$setComment('Root comment #nested');
$rootComment = status_json_comment(300, 0, $actor);
$rootCommentId = (int) ($rootComment['comment_id'] ?? 0);
$setComment('First reply');
$firstReply = status_json_comment(300, $rootCommentId, $actor);
$firstReplyId = (int) ($firstReply['comment_id'] ?? 0);
$setComment('Nested reply');
$nestedReply = status_json_comment(300, $firstReplyId, $actor);
$nestedReplyId = (int) ($nestedReply['comment_id'] ?? 0);
$assert($rootCommentId > 0 && $firstReplyId > 0 && $nestedReplyId > 0, 'Comment creation persists a root and replies.');
$assert((int) $value("SELECT parent_id FROM content_comments WHERE id = {$nestedReplyId}") === $rootCommentId, 'Replies deeper than one level attach to the root comment.');
$commentReaction = status_json_comment_like($rootCommentId, $actor);
$assert((bool) ($commentReaction['comment_like']['liked'] ?? false), 'A comment reaction is added.');
$commentReaction = status_json_comment_like($rootCommentId, $actor);
$assert(!(bool) ($commentReaction['comment_like']['liked'] ?? true), 'A comment reaction is removed.');

$_POST = ['body' => 'Updated status ' . $tagText];
$payload->setValue(null, null);
$updatedStatus = status_json_update(300, $actor);
$assert((int) ($updatedStatus['status_id'] ?? 0) === 300, 'Status editing keeps the existing aggregate identity.');
$assert((int) $value('SELECT COUNT(*) FROM content_tags WHERE content_id = 300') === 12, 'Status editing synchronizes more than ten tags atomically.');

$_POST = ['body' => 'Created through the monolith write path #created'];
$payload->setValue(null, null);
$createdStatus = status_json_create($actor);
$createdStatusId = (int) ($createdStatus['status_id'] ?? 0);
$assert($createdStatusId > 300 && (int) $value("SELECT COUNT(*) FROM content WHERE id = {$createdStatusId}") === 1, 'Status creation commits the aggregate.');
$assert((int) $value("SELECT COUNT(*) FROM content_tags WHERE content_id = {$createdStatusId}") === 1, 'Status creation synchronizes tag relations.');
$deletedStatus = status_json_delete($createdStatusId, $actor);
$assert((int) ($deletedStatus['deleted_status_id'] ?? 0) === $createdStatusId, 'Status deletion returns the deleted aggregate identity.');
$assert((int) $value("SELECT COUNT(*) FROM content WHERE id = {$createdStatusId}") === 0, 'Status deletion removes the created aggregate.');

$_POST = [
    'body' => 'Scheduled through the monolith write path #scheduled',
    'schedule_post' => '1',
    'scheduled_at' => '2030-01-02T03:04',
];
$payload->setValue(null, null);
$scheduledStatus = status_json_create($actor);
$scheduledStatusId = (int) ($scheduledStatus['status_id'] ?? 0);
$scheduledItem = status_find($scheduledStatusId);
$guestScheduledIds = public_status_ids_page(public_status_id_query(['id' => 1, 'role' => 'user']), 50);
$authorScheduledIds = public_status_ids_page(public_status_id_query($actor), 50);
$adminScheduledIds = public_status_ids_page(public_status_id_query(['id' => 9, 'role' => 'admin']), 50);
$assert((string) $value("SELECT published_at FROM content WHERE id = {$scheduledStatusId}") === '2030-01-02 03:04:00', 'Scheduled status stores the requested future publication time.');
$assert((string) ($scheduledStatus['message'] ?? '') === t('account.messages.status_scheduled'), 'Scheduled creation confirms scheduling rather than immediate publication.');
$assert(status_is_scheduled($scheduledItem), 'Future publication time marks a status as scheduled.');
$assert(!status_can_view($scheduledItem, ['id' => 1, 'role' => 'user']), 'Scheduled status is hidden from another member.');
$assert(status_can_view($scheduledItem, $actor), 'Scheduled status remains visible to its author.');
$assert(status_can_view($scheduledItem, ['id' => 9, 'role' => 'admin']), 'Scheduled status remains visible to administrators.');
$assert(!in_array($scheduledStatusId, $guestScheduledIds, true), 'Public feed query excludes scheduled statuses.');
$assert(in_array($scheduledStatusId, $authorScheduledIds, true), 'Author feed query includes the author scheduled statuses.');
$assert(in_array($scheduledStatusId, $adminScheduledIds, true), 'Admin feed query includes scheduled statuses.');
$_POST = ['body' => 'Edited scheduled post @1'];
$payload->setValue(null, null);
status_json_update($scheduledStatusId, $actor);
$assert((int) $value('SELECT COUNT(*) FROM notifications') === 0, 'Editing a scheduled status does not send mention notifications before publication.');

author_follow(2, 1);
$assert(author_is_followed(2, 1), 'Following creates the social relation.');
$assert((int) $value("SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND notification_key = 'follow:1:2'") === 1, 'Following creates one notification for the followed profile.');
$assert(Notifications::targetUrl(['type' => 'follow', 'actor_id' => 2]) === author_url(2), 'A follow notification links to the follower profile.');
author_unfollow(2, 1);
$assert(!author_is_followed(2, 1), 'Unfollowing removes the social relation.');
author_follow(2, 1);
$assert(author_is_followed(2, 1), 'Following can be restored after an unfollow.');
$assert((int) $value("SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND notification_key = 'follow:1:2'") === 1, 'Repeated follows reuse one notification and do not resend email.');
$database->exec("DELETE FROM notifications WHERE user_id = 1 AND notification_key = 'follow:1:2'");

$database->exec("INSERT INTO content_comments (id, content_id, parent_id, user_id, body) VALUES (10, 200, NULL, 2, 'parent'), (11, 200, 10, 3, 'reply'), (20, 100, NULL, 2, 'status comment')");
$database->exec('INSERT INTO comment_likes (comment_id, user_id) VALUES (10, 4), (11, 4), (20, 4)');
$database->exec("INSERT INTO notifications (id, user_id, content_id, comment_id, type, notification_key, created_at, updated_at) VALUES (1, 1, 200, 10, 'content_comment', 'test:1', '$now', '$now'), (2, 1, 200, 11, 'content_comment', 'test:2', '$now', '$now'), (3, 1, 100, 20, 'content_comment', 'test:3', '$now', '$now'), (4, 1, 100, NULL, 'content_like', 'test:4', '$now', '$now')");
$database->exec('CREATE TRIGGER fail_comment_delete BEFORE DELETE ON content_comments WHEN OLD.id = 10 BEGIN SELECT RAISE(ABORT, \'forced comment failure\'); END');
$commentFailed = false;
try {
    status_delete_comment_rows(10);
} catch (PDOException) {
    $commentFailed = true;
}
$assert($commentFailed, 'A comment aggregate failure is propagated.');
$assert((int) $value('SELECT COUNT(*) FROM content_comments WHERE content_id = 200') === 2, 'Failed comment deletion restores parent and reply.');
$assert((int) $value('SELECT COUNT(*) FROM comment_likes WHERE comment_id IN (10, 11)') === 2, 'Failed comment deletion restores reactions.');
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE comment_id IN (10, 11)') === 2, 'Failed comment deletion restores notifications.');
$database->exec('DROP TRIGGER fail_comment_delete');
status_delete_comment_rows(10);
$assert((int) $value('SELECT COUNT(*) FROM content_comments WHERE content_id = 200') === 0, 'Successful comment deletion removes parent and reply.');
$assert((int) $value('SELECT COUNT(*) FROM comment_likes WHERE comment_id IN (10, 11)') === 0, 'Successful comment deletion removes reactions.');
$assert((int) $value('SELECT COUNT(*) FROM notifications WHERE comment_id IN (10, 11)') === 0, 'Successful comment deletion removes notifications.');

$database->exec("INSERT INTO content_likes (content_id, user_id) VALUES (100, 2)");
$database->exec("INSERT INTO content_images (content_id, path) VALUES (100, 'status.webp')");
$database->exec("INSERT INTO content_reports (id, content_id, reporter_id, status) VALUES (1, 100, 2, 'open')");
$database->exec('CREATE TRIGGER fail_content_delete BEFORE DELETE ON content WHEN OLD.id = 100 BEGIN SELECT RAISE(ABORT, \'forced content failure\'); END');
$contentFailed = false;
try {
    status_delete_content(100, true, true, false);
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
$imagePath = status_delete_content(100, true, true, false);
$assert($imagePath === 'status.webp', 'Status deletion returns the external file cleanup path.');
foreach (['content', 'content_likes', 'content_comments', 'comment_likes', 'content_tags', 'content_links', 'content_images', 'content_reports', 'notifications'] as $table) {
    $column = $table === 'comment_likes' ? 'comment_id' : ($table === 'content' ? 'id' : 'content_id');
    $where = $table === 'comment_likes' ? '= 20' : '= 100';
    $assert((int) $value("SELECT COUNT(*) FROM {$table} WHERE {$column} {$where}") === 0, "Successful status deletion removes {$table}.");
}

$owner = ['id' => 1, 'role' => 'user'];
$other = ['id' => 2, 'role' => 'user'];
$admin = ['id' => 9, 'role' => 'admin'];
$assert(status_can_edit(['author_id' => 1], $owner), 'A status owner may edit an unlocked status.');
$assert(!status_can_edit(['author_id' => 1, 'edit_locked_at' => $now], $owner), 'A moderation lock blocks owner edits.');
$assert(status_can_edit(['author_id' => 1, 'edit_locked_at' => $now], $admin), 'An administrator may edit a locked status.');
$assert(!status_can_delete(['author_id' => 1], $other), 'Another user may not delete a status.');
$assert(status_can_delete(['author_id' => 1], $admin), 'An administrator may delete a status.');
$assert(status_comment_can_edit(['user_id' => 2], $other), 'A comment author may edit the comment.');
$assert(!status_comment_can_edit(['user_id' => 2], $owner), 'The status owner may not rewrite another user comment.');
$assert(status_comment_can_delete(['user_id' => 2, 'content_id' => 200], $owner), 'The status owner may delete a comment on the status.');

$unique = null;
try {
    $database->exec("INSERT INTO terms (name) VALUES ('duplicate'), ('duplicate')");
} catch (PDOException $exception) {
    $unique = $exception;
}
$assert($unique instanceof PDOException && db_unique_violation($unique), 'Unique constraint races are recognized narrowly.');
$notNull = null;
try {
    $database->exec('INSERT INTO terms (name) VALUES (NULL)');
} catch (PDOException $exception) {
    $notNull = $exception;
}
$assert($notNull instanceof PDOException && !db_unique_violation($notNull), 'Non-unique constraint failures are not swallowed.');

$api = (string) file_get_contents($root . '/App/Api.php');
$statusStart = strpos($api, 'public static function statusAction');
$statusEnd = strpos($api, 'public static function notifications', (int) $statusStart);
$statusAction = $statusStart !== false && $statusEnd !== false ? substr($api, $statusStart, $statusEnd - $statusStart) : '';
$authOffset = strpos($statusAction, 'require_auth(');
$csrfOffset = strpos($statusAction, 'csrf_require();');
$intervalOffset = strpos($statusAction, 'status_json_require_session_interval($action);');
$dispatchOffset = strpos($statusAction, 'return match ($action)');
$assert(
    $authOffset !== false && $csrfOffset > $authOffset && $intervalOffset > $csrfOffset && $dispatchOffset > $intervalOffset,
    'Every status mutation retains auth, CSRF and interval checks before dispatch.'
);

$moderation = (string) file_get_contents($root . '/Public/admin/moderation/reports.php');
$assert(
    str_contains($moderation, "\$decision !== 'remove'"),
    'Removal notifications drop the foreign-key target before content deletion.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS write-path policy and transactional integrity (' . $checks . " checks)\n";
