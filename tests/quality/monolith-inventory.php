<?php
declare(strict_types=1);

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

$assert($inventory['production_php_files'] === 111, 'Production PHP inventory remains explicit.');
$assert($inventory['app_php_files'] === 18, 'App retains the 18-file monolith baseline.');
$assert($inventory['app_class_bearing_files'] === 15, 'App retains 15 class-bearing files.');
$assert($inventory['unreferenced_global_functions'] === [], 'No unreferenced global function remains.');
$assert($inventory['unreferenced_private_methods'] === [], 'No unreferenced private method remains.');

$contractOnly = array_column($inventory['contract_only_global_functions'], 'name');
sort($contractOnly, SORT_STRING);
$assert(
    $contractOnly === ['sanitize_html', 'sitemap_url'],
    'Production-only facades are retained only with explicit contract tests.'
);

$functions = (string) file_get_contents($root . '/App/functions.php');
$core = (string) file_get_contents($root . '/App/Core.php');
$assert(!str_contains($core, 'function requireMethod('), 'Dead Core::requireMethod() stays removed.');
$assert(
    !str_contains($functions, "array_values(array_unique(array_filter(array_map('intval'"),
    'Positive-ID normalization uses its single procedural owner.'
);
$assert(substr_count($functions, 'positive_int_ids(') >= 12, 'Repeated positive-ID call sites use the shared normalizer.');
$assert(substr_count($functions, 'status_comment_tree_rows(') === 3, 'Comment tree traversal has one owner and two consumers.');
$assert(!str_contains($functions, 'if ($options === [])'), 'Impossible admin per-page fallback stays removed.');
$assert(
    preg_match_all("/\\(string\\) \\(\\$[A-Za-z]+\\['role'\\] \\?\\? ''\\) === 'admin'/", $functions) === 1,
    'Administrator identity comparison is centralized in user_is_admin().'
);

foreach ([
    'Bootstrap, runtime, configuration, site identity and media.',
    'Users, authentication, profiles, email and the social graph.',
    'Moderation, abuse limits and input sanitization.',
    'Public status read model, rendering preparation and feed queries.',
    'Public search, suggestions and bounded fallback scans.',
    'Status, comment and reaction policies plus transactional write paths.',
    'Scheduled maintenance, runtime health and request-lifetime activity.',
    'Stable 2.0.x procedural facades retained for routes and extensions.',
    'Administration filtering, pagination and prepared list controls.',
    'Escaping, localization, date/time and HTTP/request facades.',
] as $section) {
    $assert(str_contains($functions, $section), 'Missing functions.php section: ' . $section);
}

$owners = array_values(array_unique(array_column($inventory['class_methods'], 'owner')));

foreach (['Core', 'Cache', 'Minifier', 'StatusLinks', 'LinkMetadata', 'UserRoles'] as $owner) {
    $assert(in_array($owner, $owners, true), 'Stable class API is inventoried: ' . $owner);
}

if (!defined('TINYCAT')) {
    define('TINYCAT', true);
}
require_once $root . '/App/bootstrap.php';

$assert(
    positive_int_ids([3, '2', 3, 0, -1, 'invalid', 2]) === [3, 2],
    'Positive-ID normalization keeps ordered unique positive values.'
);
$assert(!user_is_admin(null), 'A missing user is not an administrator.');
$assert(!user_is_admin(['role' => 'user']), 'A regular user is not an administrator.');
$assert(user_is_admin(['role' => 'admin']), 'Administrator identity uses the shared policy helper.');

$commentRows = status_comment_tree_rows([
    ['id' => 1, 'replies' => [['id' => 2, 'replies' => []]]],
    ['id' => 3, 'replies' => []],
]);
$commentIds = array_column($commentRows, 'id');
sort($commentIds, SORT_NUMERIC);
$assert($commentIds === [1, 2, 3], 'Comment-tree traversal visits every nested comment once.');
$assert(admin_per_page_options() === [10, 25, 50, 100], 'Admin pagination exposes its configured choices.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS monolith API and call-site inventory (' . $checks . " checks)\n";
