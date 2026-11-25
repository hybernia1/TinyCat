<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

use TinyCat\Support\AdminSorting;
use TinyCat\Database\Connection;
require_once __DIR__ . '/../../tools/performance/lib/ProfileDatabase.php';

(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
$database = \tinycat_test_database_as(\TinyCat\Tools\Performance\ProfileDatabase::class);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Connection::set($database);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$database->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254), email TEXT, role VARCHAR(40), status VARCHAR(24), updated_at DATETIME)');
$database->exec("CREATE TABLE social_posts (kind VARCHAR(40) NOT NULL DEFAULT 'post', id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, author_id INT UNSIGNED, body TEXT, published_at DATETIME DEFAULT ('2026-01-01 00:00:00'), INDEX content_feed_index (published_at,id), INDEX content_sidebar_index (published_at,author_id,id), INDEX content_author_index (author_id,published_at,id))");
$database->exec('CREATE TABLE bot_sources (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, bot_user_id INT UNSIGNED, name TEXT, enabled INTEGER, interval_minutes INTEGER, last_imported_at TEXT)');
$database->exec('CREATE TABLE bot_source_posts (content_id INT UNSIGNED PRIMARY KEY, source_id INT UNSIGNED NOT NULL)');
$database->exec('CREATE TABLE content_reports (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, content_id INT UNSIGNED, reporter_id INT UNSIGNED, status VARCHAR(24), reason TEXT, created_at DATETIME)');
$database->exec('CREATE TABLE scheduler_runs (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, task TEXT, status VARCHAR(24), mode TEXT, error TEXT, duration_ms INTEGER, started_at DATETIME, actor_id INT UNSIGNED, finished_at DATETIME, options_json TEXT, summary_json TEXT)');
for ($id = 1; $id <= 15; $id++) {
    $name = chr(80 - $id);
    $database->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $name, $name . '@example.test', 'user', 'active', '2026-10-27']);
    $database->prepare('INSERT INTO scheduler_runs (id, task, status, mode, error, duration_ms, started_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $name, 'completed', 'cron', '', $id, '2026-10-27']);
}
$database->exec("INSERT INTO users VALUES (101, 'Zulu', '', 'bot', 'active', '2026-10-27'), (102, 'Alpha', '', 'bot', 'ban', '2026-10-26')");
$database->exec("INSERT INTO bot_sources VALUES (1, 101, 'Zulu', 1, 30, NULL), (2, 102, 'Alpha', 0, 60, '2026-10-27')");
$database->exec("INSERT INTO social_posts (id, author_id, body) VALUES (1, 101, 'Zulu'), (2, 102, 'Alpha')");
$database->exec("INSERT INTO content_reports VALUES (1, 1, 1, 'open', 'spam', '2026-10-26'), (2, 2, 1, 'resolved', 'other', '2026-10-27')");

foreach (['users', 'accounts', 'sources', 'bot_posts', 'runs', 'reports'] as $list) {
    $config = match ($list) { 'users' => \TinyCat\Core\Identity\ReadModel::sorting(), 'runs' => \TinyCat\Core\Tasks\Repository::sorting(), 'reports' => \TinyCat\Social\Moderation\Reports::sorting(), 'accounts' => \TinyCat\Social\Bots\Sorting::accounts(), 'sources' => \TinyCat\Social\Bots\Sorting::sources(), default => \TinyCat\Social\Bots\Sorting::LISTS[$list] };
    $_GET = [];
    $default = AdminSorting::order($config);
    $_GET = ['sort' => 'id; DROP TABLE users', 'direction' => 'desc; --'];
    $assert(AdminSorting::order($config) === $default, 'Invalid sorting uses the default: ' . $list);
    $_GET = ['sort' => ['username'], 'direction' => ['desc']];
    $assert(AdminSorting::order($config) === $default, 'Array inputs are rejected: ' . $list);
    $_GET = [];
    foreach (AdminSorting::headings($config) as $key => $heading) {
        foreach (['asc', 'desc'] as $direction) {
            $_GET = ['sort' => $key, 'direction' => $direction];
            // Exercise every allowlisted SQL expression against its real query.
            match ($list) {
                'users' => \TinyCat\Core\Identity\Actions::tc_admin_users_page(),
                'accounts' => (new ReflectionMethod(\TinyCat\Social\Bots\Administration::class, 'accountsPage'))->invoke(null),
                'sources' => \TinyCat\Social\Bots\Importer::sources(null, AdminSorting::order(\TinyCat\Social\Bots\Sorting::sources())),
                'bot_posts' => \TinyCat\Social\Bots\Administration::postsPayload(101),
                'runs' => \TinyCat\Core\Tasks\Repository::listing([], 1, 10),
                'reports' => \TinyCat\Social\Moderation\Reports::tc_admin_moderation_reports_payload()['items'],
            };
            $assert(true, 'Valid SQL: ' . $list . '/' . $key . '/' . $direction);
        }
    }
}
$_GET = ['sort' => 'username', 'direction' => 'asc', 'per_page' => 10, 'page' => 1];
$first = \TinyCat\Core\Identity\Actions::tc_admin_users_page()['items'];
$_GET['page'] = 2;
$second = \TinyCat\Core\Identity\Actions::tc_admin_users_page()['items'];
$assert($first[0]['username'] === 'A' && $second[0]['username'] === 'K', 'Users sort before pagination');
$_GET = ['sort' => 'task', 'direction' => 'asc'];
$runs = \TinyCat\Core\Tasks\Repository::listing([], 2, 10);
$assert($runs['runs'][0]['task'] === 'K', 'Run history sorts before pagination');
$_GET = ['sort' => 'username', 'direction' => 'asc'];
$accounts = (new ReflectionMethod(\TinyCat\Social\Bots\Administration::class, 'accountsPage'))->invoke(null);
$assert($accounts['items'][0]['username'] === 'Alpha', 'Bot accounts sort by name');
$_GET = ['sort' => 'content', 'direction' => 'asc'];
$reports = \TinyCat\Social\Moderation\Reports::tc_admin_moderation_reports_payload()['items'];
$assert($reports[0]['excerpt'] === 'Alpha', 'Reports sort by content');
$_GET = [];
$reports = \TinyCat\Social\Moderation\Reports::tc_admin_moderation_reports_payload()['items'];
$assert($reports[0]['status'] === 'open', 'Reports retain open-first default');
$_GET = ['sort' => 'username', 'direction' => 'asc'];
$heading = AdminSorting::headings(\TinyCat\Core\Identity\ReadModel::sorting(), ['q' => 'A&B', 'role' => 'user', 'per_page' => 50, 'page' => 3])['username'];
parse_str((string) parse_url($heading['url'], PHP_URL_QUERY), $query);
$assert($query['q'] === 'A&B' && $query['role'] === 'user' && $query['per_page'] === '50' && $query['page'] === '1' && $query['direction'] === 'desc', 'Sorting retains filters and resets page');
$assert($heading['aria'] === 'ascending' && $heading['ascending'] === true, 'Active direction is accessible');
$html = \TinyCat\Support\Components::render('ui/sort-heading', ['label' => '<Name>', 'order' => $heading]);
$assert(str_contains($html, '&lt;Name&gt;') && str_contains($html, 'data-ajax-target="#users-list"') && str_contains($html, 'data-history='), 'Shared heading escapes labels and provides AJAX/history state');
$database->exec("INSERT INTO users VALUES (16, 'A', '', 'user', 'active', '2026-10-27'), (17, 'A', '', 'user', 'active', '2026-10-27')");
$_GET = ['sort' => 'username', 'direction' => 'asc'];
$ties = \TinyCat\Core\Identity\Actions::tc_admin_users_page()['items'];
$assert(array_column(array_slice($ties, 0, 3), 'id') === [17, 16, 15], 'Equal values have deterministic ID ordering');
// Historical task rows remain readable after their domain owner is disabled.
$database->exec("UPDATE scheduler_runs SET task = 'feeds', summary_json = '{\"count\":2}' WHERE id = 1");
$withoutBots = new \TinyCat\Core\Modules\Catalog(array_values(array_filter(\TinyCat\Core\Modules\Resources::catalog()->definitions(), static fn ($definition): bool => $definition->id !== 'tinycat.social')));
\TinyCat\Core\Modules\Resources::withCatalog($withoutBots, static function () use ($assert): void {
    $detail = \TinyCat\Core\Tasks\Administration::runDetail(1);
    $assert($detail !== null && $detail['sources'] === [] && $detail['summary'] === ['count' => 2], 'Inactive task history loads without any bot tables or detail callbacks');
});

$database->exec('ALTER TABLE users ADD COLUMN created_at DATETIME');
$_GET = []; $database->queries = [];
$allUsers = \TinyCat\Core\Identity\Actions::tc_admin_users_api_payload();
$assert($allUsers['pagination']['total'] === $allUsers['stats']['total'], 'Unfiltered pagination uses the same managed population as statistics');
$assert(count(array_filter($database->queries, static fn (array $query): bool => str_contains($query['sql'], 'SELECT COUNT(*) FROM ('))) === 0, 'Unfiltered listing avoids a duplicate full count');
$_GET = ['q' => 'O']; $database->queries = [];
$filteredUsers = \TinyCat\Core\Identity\Actions::tc_admin_users_api_payload();
$assert($filteredUsers['pagination']['total'] < $filteredUsers['stats']['total'], 'Filtered pagination retains its own exact count');
$assert(count(array_filter($database->queries, static fn (array $query): bool => str_contains($query['sql'], 'SELECT COUNT(*) FROM ('))) === 1, 'Filtered listing still executes the required selection count');
echo "PASS admin sorting SQL, pagination, defaults, invalid inputs and headings ({$checks} checks)\n";
