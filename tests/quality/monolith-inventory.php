<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

$root = dirname(__DIR__, 2);
require_once $root . '/tools/monolith-inventory.php';

$inventory = tc_monolith_inventory($root);
$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(is_file($root . '/App/Http/Api.php'), 'Modular API transport is present.');
$assert(is_file($root . '/App/Database/Connection.php'), 'Database infrastructure has its own owner.');
$assert(is_file($root . '/App/modules.php'), 'Built-in module composition is explicit.');
$assert($inventory['unreferenced_global_functions'] === [], 'No unreferenced global function remains.');
$assert($inventory['unreferenced_private_methods'] === [], 'No unreferenced private method remains.');

$assert(array_filter($inventory['global_functions'], static fn (array $item): bool => str_starts_with($item['file'], 'App/')) === [], 'Production application has no procedural global facade.');
$owners = array_values(array_unique(array_column($inventory['class_methods'], 'owner')));
foreach (['Connection', 'Router', 'Authentication', 'Posts', 'Comments', 'Inbox', 'Importer', 'Store'] as $owner) {
    $assert(in_array($owner, $owners, true), 'Module implementation is inventoried: ' . $owner);
}

if (!defined('TINYCAT')) {
    define('TINYCAT', true);
}
require_once $root . '/App/bootstrap.php';

$assert(
    \TinyCat\Support\Text::positive_int_ids([3, '2', 3, 0, -1, 'invalid', 2]) === [3, 2],
    'Positive-ID normalization keeps ordered unique positive values.'
);
$assert(!\TinyCat\Users\Profiles::user_is_admin(null), 'A missing user is not an administrator.');
$assert(!\TinyCat\Users\Profiles::user_is_admin(['role' => 'user']), 'A regular user is not an administrator.');
$assert(\TinyCat\Users\Profiles::user_is_admin(['role' => 'admin']), 'Administrator identity uses the shared policy helper.');

$commentRows = \TinyCat\Status\Comments::status_comment_tree_rows([
    ['id' => 1, 'replies' => [['id' => 2, 'replies' => []]]],
    ['id' => 3, 'replies' => []],
]);
$commentIds = array_column($commentRows, 'id');
sort($commentIds, SORT_NUMERIC);
$assert($commentIds === [1, 2, 3], 'Comment-tree traversal visits every nested comment once.');
$assert(\TinyCat\Support\AdminViews::admin_per_page_options() === [10, 25, 50, 100], 'Admin pagination exposes its configured choices.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS monolith API and call-site inventory (' . $checks . " checks)\n";
