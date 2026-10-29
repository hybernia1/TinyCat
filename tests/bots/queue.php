<?php
declare(strict_types=1);
require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/bootstrap.php';
use TinyCat\Bots\Queue;
use TinyCat\Database\Connection;
use TinyCat\Support\Components;
(new ReflectionProperty(TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Connection::set($db);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, status TEXT)');
$db->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, author_id INTEGER, body TEXT, published_at TEXT)');
$db->exec("INSERT INTO users VALUES (1,'Zulu','bot','active'), (2,'Alpha','bot','ban'), (3,'Human','user','active')");
$insert = $db->prepare('INSERT INTO content VALUES (?, ?, ?, ?)');
for ($id = 1; $id <= 25; $id++) { $insert->execute([$id, 1, 'Queued ' . $id, '2099-01-' . str_pad((string) $id, 2, '0', STR_PAD_LEFT) . ' 12:00:00']); }
$insert->execute([26, 2, 'Blocked bot', '2099-02-01 12:00:00']);
$insert->execute([27, 3, 'Human future post', '2099-02-01 12:00:00']);
$insert->execute([28, 1, 'Published post', '2000-01-01 12:00:00']);
$insert->execute([29, 1, 'Literal 100%_<script>', '2099-02-02 12:00:00']);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$_GET = ['per_page' => 10]; $data = Queue::payload();
$assert($data['pagination']['total'] === 27 && count($data['items']) === 10, 'Only future bot posts enter the paginated queue.');
$assert($data['items'][0]['id'] === 1, 'Default order starts with the earliest planned post.');
$_GET = ['bot' => 2, 'status' => 'ban']; $data = Queue::payload();
$assert(count($data['items']) === 1 && !$data['items'][0]['can_publish'], 'Bot and status filters combine, and blocked bots cannot publish.');
$_GET = ['q' => '100%_']; $data = Queue::payload();
$assert($data['pagination']['total'] === 1 && $data['items'][0]['id'] === 29, 'Search treats SQL wildcards literally.');
$html = Components::render('bots/queue', $data + ['state' => $data]);
$assert(!str_contains($html, '<script>') && str_contains($html, 'Literal 100%_'), 'Queue excerpts preserve text without executable markup.');
foreach (['bot', 'body', 'published_at'] as $sort) {
    foreach (['asc', 'desc'] as $direction) { $_GET = compact('sort', 'direction'); $assert(count(Queue::payload()['items']) === 25, 'Every allowlisted sort executes.'); }
}
$_GET = ['bot' => 1, 'sort' => 'published_at', 'direction' => 'desc', 'per_page' => 10, 'page' => 3];
$result = Queue::publish(1);
$assert($result->meta['pagination']['total'] === 25 && $result->data['filters']['bot'] === '1', 'Publishing returns the current filtered list and pagination metadata.');
$at = $db->query('SELECT published_at FROM content WHERE id = 1')->fetchColumn();
$assert($at <= TinyCat\Support\Dates::dateDb(), 'Publish now moves the post into the public timeline.');
Queue::publish(1);
$assert($db->query('SELECT published_at FROM content WHERE id = 1')->fetchColumn() === $at, 'Repeated publication does not move the timestamp again.');
$assert(count($result->data['items']) === 5, 'Publishing updates the final page without changing other post schedules.');
$assert($db->query('SELECT published_at FROM content WHERE id = 2')->fetchColumn() === '2099-01-02 12:00:00', 'Other queued posts keep their planned publication times.');
echo "PASS bot publication queue ($checks checks)\n";
