<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Modules\{Contributions, Loader, NotificationType, Registrations, Registries, Resources};
use TinyCat\Core\Notifications\{Delivery, Inbox, Resource, Schema, Types};
use TinyCat\Database\Connection;

$db = \tinycat_test_database();
$db->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, username VARCHAR(40), status VARCHAR(20), role VARCHAR(20))');
$db->exec("INSERT INTO users VALUES (1,'recipient','active','admin'),(2,'actor','active','user')");
Schema::install($db);
$db->exec("INSERT INTO notifications(id,user_id,actor_id,subject_id,type,notification_key,payload_json,read_at,created_at,updated_at) VALUES
(1,1,2,20,'comment_like','comment:20','{\"context\":\"keep\"}','2026-01-02','2026-01-01','2026-01-03'),
(2,1,2,10,'content_like','post:10',NULL,NULL,'2026-01-01','2026-01-03'),
(3,1,NULL,NULL,'site_health_attention','site_health:1',NULL,'2026-01-02','2026-01-01','2026-01-03'),
(4,1,NULL,NULL,'vendor.unknown','unknown:99','{\"_tinycat_legacy_subject\":{\"content_id\":99}}',NULL,'2026-01-01','2026-01-03')");
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $action) use ($assert): void { try { $action(); } catch (InvalidArgumentException) { $assert(true, 'Invalid definition or delivery rejected'); return; } throw new RuntimeException('Invalid notification was accepted'); };
$columns = array_column($db->query("SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications'")->fetchAll(), 'name');
$assert(!in_array('content_id', $columns, true) && !in_array('comment_id', $columns, true) && in_array('subject_id', $columns, true), 'Core schema contains only an opaque subject');
$assert(json_decode($db->query('SELECT payload_json FROM notifications WHERE id=1')->fetchColumn(), true) === ['context' => 'keep'], 'Original producer payload is retained');
$unknown = json_decode($db->query('SELECT payload_json FROM notifications WHERE id=4')->fetchColumn(), true);
$assert($unknown['_tinycat_legacy_subject']['content_id'] === 99, 'References of an unavailable historical producer are not discarded');
$fresh = \tinycat_test_database();
$fresh->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
Schema::install($fresh);
$assert(!in_array('content_id', array_column($fresh->query("SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications'")->fetchAll(PDO::FETCH_ASSOC), 'name'), true), 'Fresh installations never create social reference columns');

Connection::set($db);
Resources::configure(Loader::load($root)->catalog);
$custom = new NotificationType('fixture.order', static fn (array $rows): array => $rows, static fn (array $row): string => $row['target'] ?? '/orders/' . $row['subject_id'], subject: 'required', message: static fn (array $row): string => 'Order updated');
$social = \TinyCat\Social\Posts\NotificationPresentation::types();
$registries = new Registries([
    'core.health' => new Contributions(notificationTypes: \TinyCat\Core\Tasks\Health\Alerts::types()),
    'social' => new Contributions(notificationTypes: $social),
    'fixture' => new Contributions(notificationInbox: true, notificationTypes: [$custom], localizedRoutes: [new \TinyCat\Core\Modules\LocalizedRoute('fixture.orders', '^/orders/[0-9]+$')]),
]);
Registrations::configure($registries);
$reject(static fn () => new NotificationType('bad id', static fn (array $rows): array => $rows, static fn (array $row): string => '/'));
$reject(static fn () => new NotificationType('valid', static fn (array $rows): array => $rows, static fn (array $row): string => '/', subject: 'unknown'));
$reject(static fn () => new Registries(['one' => new Contributions(notificationTypes: [$custom]), 'two' => new Contributions(notificationTypes: [$custom])]));
$reject(static fn () => Delivery::record(1, 'not.registered', 'bad'));
$reject(static fn () => Delivery::record(1, 'fixture.order', 'missing'));
$reject(static fn () => Delivery::record(1, 'fixture.order', 'zero', subjectId: 0));
$reject(static fn () => Delivery::record(1, 'site_health_ok', 'unexpected', subjectId: 1));
$notice = Delivery::record(1, 'fixture.order', 'order:10', subjectId: 10);
$assert(Inbox::open($notice['id'], 1) === '/orders/10', 'A module subject resolves through the same registry as core');
$read = $db->query('SELECT read_at FROM notifications WHERE id=' . $notice['id'])->fetchColumn();
$duplicate = Delivery::record(1, 'fixture.order', 'order:10', refresh: false, subjectId: 10);
$assert(!$duplicate['created'] && $db->query('SELECT read_at FROM notifications WHERE id=' . $notice['id'])->fetchColumn() === $read, 'Idempotent delivery preserves the read state');

$db->exec('CREATE TABLE social_posts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,body TEXT,author_id INT UNSIGNED,status VARCHAR(24),kind VARCHAR(40),published_at DATETIME, INDEX content_feed_index (published_at,id), INDEX content_sidebar_index (published_at,author_id,id), INDEX content_author_index (author_id,published_at,id))');
$db->exec("INSERT INTO social_posts VALUES (10,'Visible post',2,'published','post','2020-01-01'),(11,'Hidden post',2,'hidden','post','2020-01-01')");
$db->exec('CREATE TABLE social_comments (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,content_id INT UNSIGNED,user_id INT UNSIGNED,body TEXT)');
$db->exec("INSERT INTO social_comments VALUES (20,10,2,'Visible comment'),(21,11,2,'Hidden comment')");
$rows = $db->query("SELECT n.*, 'actor' AS actor_name FROM notifications n WHERE id IN (1,2) ORDER BY id")->fetchAll();
$hydrated = Types::hydrate($rows);
$assert(Types::target($hydrated[0]) === '/status/10' && $hydrated[0]['subject_text'] === 'Visible comment', 'Comment subjects resolve their parent and text in the Social owner');
$hidden = Types::hydrate([['id' => 90, 'type' => 'comment_like', 'subject_id' => 21, 'actor_id' => 2, 'actor_name' => 'actor']])[0];
$assert(Types::target($hidden) === '/notifications' && $hidden['subject_text'] === '', 'Hidden comment parents do not leak a link or excerpt');
$assert(Resource::item($hydrated[0])['message'] !== '', 'The type supplies resolved presentation rather than a client translation identifier');
\TinyCat\Social\Posts\NotificationHistory::removeForContent(10);
$assert((int) $db->query("SELECT COUNT(*) FROM notifications WHERE type IN ('content_like','comment_like')")->fetchColumn() === 0, 'Deleting a Social post removes its post and comment subjects');
$assert((int) $db->query("SELECT COUNT(*) FROM notifications WHERE type='fixture.order'")->fetchColumn() === 1, 'Identical IDs in another domain are not deleted');
$db->exec('ALTER TABLE social_comments ADD COLUMN parent_id INT UNSIGNED NULL');
$db->exec("INSERT INTO social_posts VALUES (12,'Other owner',1,'published','post','2020-01-01'),(13,'Other kind',2,'published','fixture','2020-01-01'),(20,'Overlapping post ID',1,'published','post','2020-01-01')");
$db->exec("INSERT INTO social_comments(id,content_id,user_id,body,parent_id) VALUES (10,12,1,'Overlapping comment ID',NULL),(30,12,2,'Own comment',NULL),(31,12,1,'Reply to own comment',30),(32,12,1,'Unrelated comment',NULL),(33,13,2,'Other kind comment',NULL)");
$cases = [
    ['post-like', 'content_like', 10, true], ['post-mention', 'content_mention', 11, true],
    ['report-resolved', 'report_resolved', 10, true], ['report-dismissed', 'report_dismissed', 11, true],
    ['post-comment', 'content_comment', 20, true], ['post-comment-like', 'comment_like', 21, true],
    ['own-comment', 'comment_mention', 30, true], ['reply-to-own-comment', 'content_comment', 31, true],
    ['other-domain', 'fixture.order', 10, false], ['other-post', 'content_like', 12, false],
    ['overlapping-comment', 'comment_like', 10, false], ['overlapping-post', 'content_like', 20, false],
    ['tree-post-overlap', 'content_like', 30, false],
    ['unrelated-comment', 'comment_mention', 32, false], ['other-kind-post', 'content_like', 13, false],
    ['other-kind-comment', 'comment_like', 33, false],
];
foreach ($cases as [$label, $type, $subject]) {
    Connection::insert('notifications', ['user_id' => 1, 'actor_id' => 2, 'type' => $type, 'subject_id' => $subject,
        'notification_key' => 'cleanup:' . $label, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
}
$beforeCleanup = $db->query('SELECT id FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
try {
    \TinyCat\Database\Transactions::run(static function () use ($assert): void {
        \TinyCat\Social\Posts\NotificationHistory::removeForCommentTree(30);
        $assert(Connection::find('notifications', ['notification_key' => 'cleanup:own-comment']) === null
            && Connection::find('notifications', ['notification_key' => 'cleanup:reply-to-own-comment']) === null, 'Thread cleanup removes parent and direct reply notifications');
        $assert(Connection::find('notifications', ['notification_key' => 'cleanup:tree-post-overlap']) !== null
            && Connection::find('notifications', ['notification_key' => 'cleanup:unrelated-comment']) !== null, 'Thread cleanup preserves overlapping post IDs and unrelated comments');
        \TinyCat\Social\Posts\NotificationHistory::removeForCommentTree(33);
        $assert(Connection::find('notifications', ['notification_key' => 'cleanup:other-kind-comment']) !== null, 'Thread cleanup does not adopt another publication kind');
        throw new DomainException('rollback thread cleanup');
    });
} catch (DomainException) {}
$assert($db->query('SELECT id FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === $beforeCleanup, 'Thread notification cleanup rolls back atomically');

try {
    \TinyCat\Database\Transactions::run(static function (): void {
        \TinyCat\Social\Posts\NotificationHistory::prepareAccountDelete(['id' => 2]);
        throw new DomainException('injected account rollback');
    });
} catch (DomainException) {}
$assert($db->query('SELECT id FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === $beforeCleanup, 'Set-based account cleanup rolls back all notification removals together');
\TinyCat\Database\Transactions::run(static function (): void { \TinyCat\Social\Posts\NotificationHistory::prepareAccountDelete(['id' => 2]); });
foreach ($cases as [$label, $type, $subject, $removed]) {
    $exists = Connection::find('notifications', ['notification_key' => 'cleanup:' . $label]) !== null;
    $assert($exists !== $removed, 'Account cleanup preserves subject ownership and ID namespaces: ' . $label);
}
\TinyCat\Http\Router::route('GET', '/orders/{id:[0-9]+}', static fn (): null => null, publicWeb: true);
\TinyCat\Http\Router::configurePublicWeb(static fn (): bool => false);
$assert(Types::target(['type' => 'fixture.order', 'subject_id' => 10]) === '/notifications', 'Closed public targets fall back to the core inbox without domain lists');
$assert(Types::target(['type' => 'fixture.order', 'target' => '/en/orders/10?view=full#detail']) === '/notifications', 'Localized notification targets with query and fragment obey public availability');
$assert(Types::target(['type' => 'fixture.order', 'target' => '/admin/orders/10']) === '/admin/orders/10', 'Internal notification destinations remain available');
\TinyCat\Http\Router::configurePublicWeb(static fn (): bool => true);
$assert(Types::target(['type' => 'fixture.order', 'subject_id' => 10]) === '/orders/10', 'Re-enabling public presentation restores the original target');
Registrations::configure(new Registries());
$assert(Inbox::open($notice['id'], 1) === '/notifications' && Resource::item($hydrated[0])['type'] === 'generic', 'Inactive owners retain rows and use a safe generic presentation');
$reject(static fn () => Delivery::record(1, 'fixture.order', 'inactive', subjectId: 10));
echo "PASS registered notification subjects, ownership, visibility and fallback ({$checks} checks)\n";
