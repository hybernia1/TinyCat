<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/autoload.php';
use TinyCat\Database\Connection;
use TinyCat\Social\Content\PostCreation;

$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$modules = TinyCat\Core\Modules\Loader::fromSelection($root, ['tinycat.social' => 'App/Modules/Social/module.json']);
$api = TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(locale: static fn (): string => 'en'));
TinyCat\Core\Modules\Resources::configure($modules->catalog);
$modules->register($api);
TinyCat\Core\Identity\Authentication::reset(null, true);
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON'); Connection::set($pdo);
TinyCat\Core\Identity\Schema::install($pdo); TinyCat\Social\Content\Schema::install($pdo); TinyCat\Social\Profiles\Schema::install($pdo); TinyCat\Social\Content\InteractionSchema::install($pdo);
$pdo->exec("INSERT INTO users(id, username, role) VALUES (1, 'Reader', 'user'), (2, 'Writer', 'user'), (3, 'Other', 'user')");
$own = PostCreation::create(1, PostCreation::fromText('Own'), [], publishedAt: '2000-01-01 00:00:00');
$post = PostCreation::create(2, PostCreation::fromText('Followed'), [], publishedAt: '2000-01-01 00:00:00');
PostCreation::create(3, PostCreation::fromText('Other'), [], publishedAt: '2000-01-01 00:00:00');
TinyCat\Social\Profiles\Following::author_follow(1, 2);
$assert(TinyCat\Social\Profiles\Following::author_is_followed(1, 2), 'Following commits without Notifications installed');
$following = TinyCat\Social\Posts\Feed::public_status_items_for_user_cursor(1, 10);
$assert(array_column($following, 'id') === [$post, $own], 'Following feed uses relationships and publishing visibility');
$assert(TinyCat\Social\Profiles\Pages::authorData(2) === null && TinyCat\Social\Profiles\Repository::author_activity_stats(2)['posts'] === 0, 'Author product composition is optional rather than selecting Status implicitly');
$modules->initialize();
$assert(TinyCat\Social\Profiles\Repository::author_activity_stats(2)['posts'] === 1, 'Selected social composition supplies author activity without loading absent providers');
$assert(PostCreation::fromText('https://outside.example/story')->rejection === null, 'Selected mention policy does not import absent Site or Moderation');
$events = [];
TinyCat\Social\Profiles\Integration::configure(['followed' => static function (int $id, array $actor) use (&$events): void { $events[] = [$id, $actor['id']]; }]);
TinyCat\Social\Profiles\Following::author_follow(3, 2); TinyCat\Social\Profiles\Following::author_follow(3, 2);
$assert($events === [[2, 3]], 'Explicit followed callback receives one event for the idempotent relation');
$pdo->exec('INSERT INTO social_post_likes(content_id, user_id) VALUES (' . $post . ', 1)');
$comment = (int) Connection::insert('social_comments', ['content_id' => $post, 'user_id' => 1, 'body' => 'Comment']);
$reply = (int) Connection::insert('social_comments', ['content_id' => $post, 'user_id' => 2, 'parent_id' => $comment, 'body' => 'Reply']);
$pdo->exec('INSERT INTO social_comment_likes(comment_id, user_id) VALUES (' . $reply . ', 1)');
$summary = TinyCat\Social\Posts\Posts::status_json_summary($post, ['id' => 1]);
$assert($summary['likes_count'] === 1 && $summary['comments_count'] === 2 && $summary['liked'], 'Reaction and comment projections remain owned by Status');
TinyCat\Social\Posts\NotificationHooks::configure(['removeForContent' => static function (int $id): void { throw new RuntimeException('notification transaction failure'); }]);
try { TinyCat\Social\Posts\Posts::status_delete_content($post, deleteReports: false); $assert(false, 'Related failure must stop deletion'); }
catch (RuntimeException) { $assert(TinyCat\Social\Content\Posts::find($post) !== null && (int) Connection::value('SELECT COUNT(*) FROM social_post_likes') === 1, 'Notification cleanup failure rolls back social and publishing data together'); }
TinyCat\Social\Posts\NotificationHooks::configure([]);
TinyCat\Social\Content\Comments::status_delete_comment_rows($comment);
$assert((int) Connection::value('SELECT COUNT(*) FROM social_comments') === 0 && (int) Connection::value('SELECT COUNT(*) FROM social_comment_likes') === 0, 'Comment tree cleanup works without notification tables');
TinyCat\Social\Posts\Posts::status_delete_content($post, deleteReports: false);
$assert(TinyCat\Social\Content\Posts::find($post) === null && (int) Connection::value('SELECT COUNT(*) FROM social_post_likes') === 0, 'Social cleanup delegates content deletion to Publishing');
$owners = [];
foreach ($modules->catalog->definitions() as $definition) { $owners[] = $definition->id; }
$assert(in_array('tinycat.notifications', $owners, true) && in_array('tinycat.social', $owners, true) && !in_array('tinycat.bots', $owners, true), 'Selected graph does not silently activate optional modules');
foreach (get_declared_classes() as $class) { $assert(!preg_match('/^TinyCat\\\\(?:Notifications|Bots|Settings|Site|Moderation|Search)\\\\/', $class), 'Social data services did not load optional product code: ' . $class); }
echo "PASS social data ownership, optional composition, following, comments and transactional cleanup ({$checks} checks)\n";
