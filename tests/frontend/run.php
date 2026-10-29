<?php
declare(strict_types=1);
putenv('TINYCAT_TEST_PHP=' . PHP_BINARY);

foreach (['embed.cjs', 'feed-cursor.cjs', 'email-availability.cjs', 'images.cjs', 'infrastructure.cjs', 'modules.cjs', 'user-menu.cjs', 'translation.cjs', 'locale-urls.cjs', 'language-menu.cjs', 'sidebar-viewport.cjs', 'sidebar-refresh.cjs', 'scheduler-live.cjs', 'search-navigation.cjs', 'notifications-state.cjs', 'admin-sorting.cjs', 'client-assets.cjs', 'styles.cjs'] as $test) {
    passthru('node ' . escapeshellarg(__DIR__ . '/' . $test), $exitCode);
    if ($exitCode !== 0) { exit($exitCode); }
}
