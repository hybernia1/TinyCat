<?php
declare(strict_types=1);
putenv('TINYCAT_TEST_PHP=' . PHP_BINARY);
require_once __DIR__ . '/../support/execution.php';

$tests = ['source-preview.cjs', 'embed.cjs', 'feed-cursor.cjs', 'feed-lifecycle.cjs', 'filtered-home-actions.cjs', 'email-availability.cjs', 'images.cjs', 'youtube-thumbnails.cjs', 'infrastructure.cjs', 'modules.cjs', 'user-menu.cjs', 'toasts.cjs', 'translation.cjs', 'tag-layout.cjs', 'locale-urls.cjs', 'language-menu.cjs', 'sidebar-viewport.cjs', 'sidebar-refresh.cjs', 'scheduler-live.cjs', 'search-navigation.cjs', 'notifications-state.cjs', 'admin-sorting.cjs', 'ajax-races.cjs', 'search-races.cjs', 'list-state.cjs', 'popovers.cjs', 'popover-sizing.cjs', 'popover-touch.cjs', 'comment-modals.cjs', 'ui-consistency.cjs', 'link-labels.cjs', 'dialog-history.cjs', 'form-validation.cjs', 'admin-viewport.cjs', 'dashboard.cjs', 'bot-admin-lists.cjs', 'client-assets.cjs', 'styles.cjs'];
$items = array_combine($tests, $tests);
if (\TinyCatTests\Execution::listing()) { foreach ($items as $id) { echo $id . "\n"; } exit(0); }
$allItems = $items;
$items = \TinyCatTests\Execution::select($items);
\TinyCatTests\Execution::plan($allItems, array_keys($items));
foreach ($items as $test) {
    $exitCode = \TinyCatTests\Execution::run($test, $test, 'node ' . escapeshellarg(__DIR__ . '/' . $test));
    if ($exitCode !== 0) { exit($exitCode); }
}
