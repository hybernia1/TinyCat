<?php
declare(strict_types=1);
require __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/autoload.php';

use TinyCat\Core\Modules\{Contributions, Loader, NotificationType, Registrations, Registries, Resources};
use TinyCat\Core\Notifications\{Delivery, Inbox, Resource, Schema, Types};
use TinyCat\Database\Connection;

if (($argv[1] ?? '') === '--mysql') {
    $config = require $root . '/config.php'; $database = $config['database'];
    if (!in_array($database['host'], ['localhost', '127.0.0.1'], true)) { throw new RuntimeException('Notification MySQL tests require the local service.'); }
    $db = new PDO('mysql:host=' . $database['host'] . ';port=' . ($database['port'] ?? 3306) . ';charset=utf8mb4', $database['user'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $databaseName = 'tinycat_notifications_' . bin2hex(random_bytes(6));
    $db->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function () use ($db, $databaseName): void { $db->exec('DROP DATABASE `' . $databaseName . '`'); });
    $db->exec('USE `' . $databaseName . '`');
} else {
    $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
}
$db->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, username VARCHAR(40), status VARCHAR(20), role VARCHAR(20))');
$db->exec("INSERT INTO users VALUES (1,'recipient','active','admin'),(2,'actor','active','user')");
$initial = require $root . '/App/Core/Notifications/migrations/001-initial.php'; $initial($db);
$db->exec("INSERT INTO notifications(id,user_id,actor_id,content_id,comment_id,type,notification_key,payload_json,read_at,created_at,updated_at) VALUES
(1,1,2,10,20,'comment_like','comment:20','{\"context\":\"keep\"}','2026-01-02','2026-01-01','2026-01-03'),
(2,1,2,10,NULL,'content_like','post:10',NULL,NULL,'2026-01-01','2026-01-03'),
(3,1,NULL,NULL,NULL,'site_health_attention','site_health:1',NULL,'2026-01-02','2026-01-01','2026-01-03'),
(4,1,NULL,99,NULL,'vendor.unknown','unknown:99',NULL,NULL,'2026-01-01','2026-01-03')");
$before = $db->query('SELECT id,notification_key,read_at,created_at,updated_at FROM notifications ORDER BY id')->fetchAll();
$upgrade = require $root . '/App/Core/Notifications/migrations/002-subjects.php'; $upgrade($db); $upgrade($db);
$convert = require $root . '/App/Modules/Social/migrations/publishing/interactions/003-notification-subjects.php'; $convert($db); $convert($db);
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $action) use ($assert): void { try { $action(); } catch (InvalidArgumentException) { $assert(true, 'Invalid definition or delivery rejected'); return; } throw new RuntimeException('Invalid notification was accepted'); };
$columns = array_column($db->query($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'PRAGMA table_info(notifications)' : "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications'")->fetchAll(), 'name');
$assert(!in_array('content_id', $columns, true) && !in_array('comment_id', $columns, true) && in_array('subject_id', $columns, true), 'Core schema contains only an opaque subject');
$assert($db->query('SELECT id,notification_key,read_at,created_at,updated_at FROM notifications ORDER BY id')->fetchAll() === $before, 'Migration preserves identities, keys, read state and timestamps');
$assert((int) $db->query('SELECT subject_id FROM notifications WHERE id=1')->fetchColumn() === 20 && (int) $db->query('SELECT subject_id FROM notifications WHERE id=2')->fetchColumn() === 10, 'Only Social interprets comment and post subjects');
$assert(json_decode($db->query('SELECT payload_json FROM notifications WHERE id=1')->fetchColumn(), true) === ['context' => 'keep'], 'Original producer payload is retained');
$unknown = json_decode($db->query('SELECT payload_json FROM notifications WHERE id=4')->fetchColumn(), true);
$assert($unknown['_tinycat_legacy_subject']['content_id'] === 99, 'References of an unavailable historical producer are not discarded');
$fresh = new PDO('sqlite::memory:'); Schema::install($fresh);
$assert(!in_array('content_id', array_column($fresh->query('PRAGMA table_info(notifications)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true), 'Fresh installations never create social reference columns');

Connection::set($db);
Resources::configure(Loader::load($root)->catalog);
$custom = new NotificationType('fixture.order', static fn (array $rows): array => $rows, static fn (array $row): string => '/orders/' . $row['subject_id'], subject: 'required', message: static fn (array $row): string => 'Order updated');
$social = \TinyCat\Social\Posts\NotificationPresentation::types();
$registries = new Registries([
    'core.health' => new Contributions(notificationTypes: \TinyCat\Core\Health\Alerts::types()),
    'social' => new Contributions(notificationTypes: $social),
    'fixture' => new Contributions(notificationInbox: true, notificationTypes: [$custom]),
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

$db->exec('CREATE TABLE social_posts (id INTEGER PRIMARY KEY,body TEXT,author_id INTEGER,status TEXT,kind TEXT,published_at TEXT)');
$db->exec("INSERT INTO social_posts VALUES (10,'Visible post',2,'published','post','2020-01-01'),(11,'Hidden post',2,'hidden','post','2020-01-01')");
$db->exec('CREATE TABLE social_comments (id INTEGER PRIMARY KEY,content_id INTEGER,user_id INTEGER,body TEXT)');
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
Registrations::configure(new Registries());
$assert(Inbox::open($notice['id'], 1) === '/notifications' && Resource::item($hydrated[0])['type'] === 'generic', 'Inactive owners retain rows and use a safe generic presentation');
$reject(static fn () => Delivery::record(1, 'fixture.order', 'inactive', subjectId: 10));
echo "PASS registered notification subjects, migration, ownership, visibility and fallback ({$checks} checks)\n";
