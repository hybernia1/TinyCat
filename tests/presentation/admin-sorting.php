<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

use TinyCat\Support\AdminSorting;
use TinyCat\Database\Connection;

(new ReflectionProperty(\TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Connection::set($database);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, role TEXT, status TEXT, updated_at TEXT)');
$database->exec("CREATE TABLE content (id INTEGER PRIMARY KEY, author_id INTEGER, body TEXT, published_at TEXT DEFAULT '2026-01-01 00:00:00')");
$database->exec('CREATE TABLE bot_sources (id INTEGER PRIMARY KEY, bot_user_id INTEGER, name TEXT, enabled INTEGER, interval_minutes INTEGER, last_imported_at TEXT)');
$database->exec('CREATE TABLE bot_source_posts (content_id INTEGER PRIMARY KEY, source_id INTEGER NOT NULL)');
$database->exec('CREATE TABLE content_reports (id INTEGER PRIMARY KEY, content_id INTEGER, reporter_id INTEGER, status TEXT, reason TEXT, created_at TEXT)');
$database->exec('CREATE TABLE scheduler_runs (id INTEGER PRIMARY KEY, task TEXT, status TEXT, mode TEXT, error TEXT, duration_ms INTEGER, started_at TEXT)');
for ($id = 1; $id <= 15; $id++) {
    $name = chr(80 - $id);
    $database->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $name, $name . '@example.test', 'user', 'active', '2026-10-27']);
    $database->prepare('INSERT INTO scheduler_runs VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $name, 'completed', 'cron', '', $id, '2026-10-27']);
}
$database->exec("INSERT INTO users VALUES (101, 'Zulu', '', 'bot', 'active', '2026-10-27'), (102, 'Alpha', '', 'bot', 'ban', '2026-10-26')");
$database->exec("INSERT INTO bot_sources VALUES (1, 101, 'Zulu', 1, 30, NULL), (2, 102, 'Alpha', 0, 60, '2026-10-27')");
$database->exec("INSERT INTO content (id, author_id, body) VALUES (1, 101, 'Zulu'), (2, 102, 'Alpha')");
$database->exec("INSERT INTO content_reports VALUES (1, 1, 1, 'open', 'spam', '2026-10-26'), (2, 2, 1, 'resolved', 'other', '2026-10-27')");

foreach (['users', 'accounts', 'sources', 'bot_posts', 'runs', 'reports'] as $list) {
    $_GET = [];
    $default = AdminSorting::order($list);
    $_GET = ['sort' => 'id; DROP TABLE users', 'direction' => 'desc; --'];
    $assert(AdminSorting::order($list) === $default, 'Invalid sorting uses the default: ' . $list);
    $_GET = ['sort' => ['username'], 'direction' => ['desc']];
    $assert(AdminSorting::order($list) === $default, 'Array inputs are rejected: ' . $list);
    $_GET = [];
    foreach (AdminSorting::headings($list) as $key => $heading) {
        foreach (['asc', 'desc'] as $direction) {
            $_GET = ['sort' => $key, 'direction' => $direction];
            // Exercise every allowlisted SQL expression against its real query.
            match ($list) {
                'users' => \TinyCat\Users\Actions::tc_admin_users_page(),
                'accounts' => (new ReflectionMethod(\TinyCat\Bots\Administration::class, 'accountsPage'))->invoke(null),
                'sources' => \TinyCat\Bots\Importer::sources(null, AdminSorting::order('sources')),
                'bot_posts' => \TinyCat\Bots\Administration::postsPayload(101),
                'runs' => \TinyCat\Scheduler\Repository::listing([], 1, 10),
                'reports' => \TinyCat\Moderation\Reports::tc_admin_moderation_reports(),
            };
            $assert(true, 'Valid SQL: ' . $list . '/' . $key . '/' . $direction);
        }
    }
}
$_GET = ['sort' => 'username', 'direction' => 'asc', 'per_page' => 10, 'page' => 1];
$first = \TinyCat\Users\Actions::tc_admin_users_page()['items'];
$_GET['page'] = 2;
$second = \TinyCat\Users\Actions::tc_admin_users_page()['items'];
$assert($first[0]['username'] === 'A' && $second[0]['username'] === 'K', 'Users sort before pagination');
$_GET = ['sort' => 'task', 'direction' => 'asc'];
$runs = \TinyCat\Scheduler\Repository::listing([], 2, 10);
$assert($runs['runs'][0]['task'] === 'K', 'Run history sorts before pagination');
$_GET = ['sort' => 'username', 'direction' => 'asc'];
$accounts = (new ReflectionMethod(\TinyCat\Bots\Administration::class, 'accountsPage'))->invoke(null);
$assert($accounts['items'][0]['username'] === 'Alpha', 'Bot accounts sort by name');
$_GET = ['sort' => 'content', 'direction' => 'asc'];
$reports = \TinyCat\Moderation\Reports::tc_admin_moderation_reports();
$assert($reports[0]['body'] === 'Alpha', 'Reports sort by content');
$_GET = [];
$reports = \TinyCat\Moderation\Reports::tc_admin_moderation_reports();
$assert($reports[0]['status'] === 'open', 'Reports retain open-first default');
$_GET = ['sort' => 'username', 'direction' => 'asc'];
$heading = AdminSorting::headings('users', ['q' => 'A&B', 'role' => 'user', 'per_page' => 50, 'page' => 3])['username'];
parse_str((string) parse_url($heading['url'], PHP_URL_QUERY), $query);
$assert($query['q'] === 'A&B' && $query['role'] === 'user' && $query['per_page'] === '50' && $query['page'] === '1' && $query['direction'] === 'desc', 'Sorting retains filters and resets page');
$assert($heading['aria'] === 'ascending' && $heading['ascending'] === true, 'Active direction is accessible');
$html = \TinyCat\Support\Components::render('ui/sort-heading', ['label' => '<Name>', 'order' => $heading]);
$assert(str_contains($html, '&lt;Name&gt;') && str_contains($html, 'data-ajax-target="#users-list"') && str_contains($html, 'data-history='), 'Shared heading escapes labels and provides AJAX/history state');
$database->exec("INSERT INTO users VALUES (16, 'A', '', 'user', 'active', '2026-10-27'), (17, 'A', '', 'user', 'active', '2026-10-27')");
$_GET = ['sort' => 'username', 'direction' => 'asc'];
$ties = \TinyCat\Users\Actions::tc_admin_users_page()['items'];
$assert(array_column(array_slice($ties, 0, 3), 'id') === [17, 16, 15], 'Equal values have deterministic ID ordering');
echo "PASS admin sorting SQL, pagination, defaults, invalid inputs and headings ({$checks} checks)\n";
