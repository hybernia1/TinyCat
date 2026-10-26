<?php
declare(strict_types=1);
putenv('TINYCAT_TEST_PHP=' . PHP_BINARY);

foreach (['infrastructure.cjs', 'modules.cjs', 'client-assets.cjs', 'styles.cjs'] as $test) {
    passthru('node ' . escapeshellarg(__DIR__ . '/' . $test), $exitCode);
    if ($exitCode !== 0) { exit($exitCode); }
}
