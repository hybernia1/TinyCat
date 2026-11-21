<?php
declare(strict_types=1);
// Registered scheduler titles and parameter labels add at most 1 KiB raw to affected admin scopes.
// The distributed superset must fit the existing budgets, including optional module assets.
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/assets/asset-report.php') . ' --check --assembly=platform', $exitCode);
exit($exitCode);
