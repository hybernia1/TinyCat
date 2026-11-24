<?php
declare(strict_types=1);

define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/Core/Runtime/autoload.php';
use TinyCat\Database\Connection;
use TinyCat\Social\Posts\PostCreation;

$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$modules = TinyCat\Core\Modules\Loader::fromSelection($root, ['tinycat.social' => 'App/Modules/Social/module.json']);
$api = TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(locale: static fn (): string => 'en'));
TinyCat\Core\Modules\Resources::configure($modules->catalog);
$modules->register($api);
TinyCat\Core\Identity\Authentication::reset(null, true);
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON'); Connection::set($pdo);
TinyCat\Core\Identity\Schema::install($pdo); TinyCat\Social\Posts\Schema::install($pdo); TinyCat\Social\Profiles\Schema::install($pdo); TinyCat\Social\Discussion\Schema::install($pdo);
$pdo->exec("INSERT INTO users(id, username, role) VALUES (1, 'Reader', 'user'), (2, 'Writer', 'user'), (3, 'Other', 'user')");
$own = PostCreation::create(1, PostCreation::fromText('Own'), [], publishedAt: '2000-01-01 00:00:00');
$post = PostCreation::create(2, PostCreation::fromText('Followed'), [], publishedAt: '2000-01-01 00:00:00');
PostCreation::create(3, PostCreation::fromText('Other'), [], publishedAt: '2000-01-01 00:00:00');
TinyCat\Social\Profiles\Following::author_follow(1, 2);
$assert(TinyCat\Social\Profiles\Following::author_is_followed(1, 2), 'Following commits without Notifications installed');
$following = TinyCat\Social\Posts\Feed::public_status_items_for_user_cursor(1, 10);
$assert(array_column($following, 'id') === [$post, $own], 'Following feed uses relationships and publishing visibility');
$modules->initialize();
$assert(TinyCat\Social\Posts\AuthorStatistics::author_activity_stats(2)['posts'] === 1, 'Selected social composition supplies author activity without loading absent providers');
$assert(PostCreation::fromText('https://outside.example/story')->rejection === null, 'Selected mention policy does not import absent Site or Moderation');
TinyCat\Social\Profiles\Following::author_follow(3, 2); TinyCat\Social\Profiles\Following::author_follow(3, 2);
$assert((int) Connection::value('SELECT COUNT(*) FROM user_followers WHERE user_id = 2 AND follower_id = 3') === 1, 'Following remains idempotent without notification tables');
$pdo->exec('INSERT INTO social_post_likes(content_id, user_id) VALUES (' . $post . ', 1)');
$comment = (int) Connection::insert('social_comments', ['content_id' => $post, 'user_id' => 1, 'body' => 'Comment']);
$reply = (int) Connection::insert('social_comments', ['content_id' => $post, 'user_id' => 2, 'parent_id' => $comment, 'body' => 'Reply']);
$pdo->exec('INSERT INTO social_comment_likes(comment_id, user_id) VALUES (' . $reply . ', 1)');
$summary = TinyCat\Social\Posts\Posts::status_json_summary($post, ['id' => 1]);
$assert($summary['likes_count'] === 1 && $summary['comments_count'] === 2 && $summary['liked'], 'Reaction and comment projections remain owned by Status');
TinyCat\Core\Notifications\Schema::install($pdo);
TinyCat\Core\Modules\Registrations::configure(new TinyCat\Core\Modules\Registries([
    'fixture' => new TinyCat\Core\Modules\Contributions(notificationInbox: true),
]));
Connection::insert('notifications', ['user_id' => 1, 'actor_id' => 2, 'type' => 'content_like', 'subject_id' => $post, 'notification_key' => 'content_like:' . $post, 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01']);
$pdo->exec("CREATE TRIGGER reject_notification_delete BEFORE DELETE ON notifications BEGIN SELECT RAISE(ABORT, 'notification cleanup failure'); END");
try { TinyCat\Social\Posts\Posts::status_delete_content($post, deleteReports: false); $assert(false, 'Database failure must stop deletion'); }
catch (PDOException) { $assert(TinyCat\Social\Posts\Repository::find($post) !== null && (int) Connection::value('SELECT COUNT(*) FROM social_post_likes') === 1, 'Notification cleanup failure preserves the post and its reactions'); }
$assert((int) Connection::value('SELECT COUNT(*) FROM notifications') === 1, 'Failed cleanup retains the notification');
$pdo->exec('DROP TRIGGER reject_notification_delete');
TinyCat\Social\Discussion\Comments::status_delete_comment_rows($comment);
$assert((int) Connection::value('SELECT COUNT(*) FROM social_comments') === 0 && (int) Connection::value('SELECT COUNT(*) FROM social_comment_likes') === 0, 'Comment tree cleanup removes replies and reactions');
TinyCat\Social\Posts\Posts::status_delete_content($post, deleteReports: false);
$assert(TinyCat\Social\Posts\Repository::find($post) === null && (int) Connection::value('SELECT COUNT(*) FROM social_post_likes') === 0, 'Social cleanup delegates content deletion to Publishing');
$assert((int) Connection::value('SELECT COUNT(*) FROM notifications') === 0, 'Successful post deletion removes its notifications');
$owners = [];
foreach ($modules->catalog->definitions() as $definition) { $owners[] = $definition->id; }
$assert(in_array('tinycat.notifications', $owners, true) && in_array('tinycat.social', $owners, true) && !in_array('tinycat.bots', $owners, true), 'Selected graph does not silently activate optional modules');
foreach (get_declared_classes() as $class) { $assert(!preg_match('/^TinyCat\\\\(?:Notifications|Bots|Settings|Site|Moderation|Search)\\\\/', $class), 'Social data services did not load optional product code: ' . $class); }
echo "PASS social data ownership, optional composition, following, comments and transactional cleanup ({$checks} checks)\n";
