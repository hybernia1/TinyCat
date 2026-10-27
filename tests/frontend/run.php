<?php
declare(strict_types=1);
putenv('TINYCAT_TEST_PHP=' . PHP_BINARY);

foreach (['email-availability.cjs', 'images.cjs', 'infrastructure.cjs', 'modules.cjs', 'translation.cjs', 'locale-urls.cjs', 'language-menu.cjs', 'sidebar-viewport.cjs', 'admin-sorting.cjs', 'client-assets.cjs', 'styles.cjs'] as $test) {
    passthru('node ' . escapeshellarg(__DIR__ . '/' . $test), $exitCode);
    if ($exitCode !== 0) { exit($exitCode); }
}
