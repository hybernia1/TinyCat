<?php
declare(strict_types=1);
putenv('TINYCAT_TEST_PHP=' . PHP_BINARY);

foreach (['source-preview.cjs', 'embed.cjs', 'feed-cursor.cjs', 'feed-lifecycle.cjs', 'filtered-home-actions.cjs', 'email-availability.cjs', 'images.cjs', 'youtube-thumbnails.cjs', 'infrastructure.cjs', 'modules.cjs', 'user-menu.cjs', 'toasts.cjs', 'translation.cjs', 'locale-urls.cjs', 'language-menu.cjs', 'sidebar-viewport.cjs', 'sidebar-refresh.cjs', 'scheduler-live.cjs', 'search-navigation.cjs', 'notifications-state.cjs', 'admin-sorting.cjs', 'ajax-races.cjs', 'search-races.cjs', 'list-state.cjs', 'popovers.cjs', 'popover-sizing.cjs', 'popover-touch.cjs', 'comment-modals.cjs', 'ui-consistency.cjs', 'link-labels.cjs', 'dialog-history.cjs', 'form-validation.cjs', 'admin-viewport.cjs', 'dashboard.cjs', 'bot-admin-lists.cjs', 'client-assets.cjs', 'styles.cjs'] as $test) {
    passthru('node ' . escapeshellarg(__DIR__ . '/' . $test), $exitCode);
    if ($exitCode !== 0) { exit($exitCode); }
}
