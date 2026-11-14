<?php
declare(strict_types=1);
// The distributed superset must fit the existing budgets, including optional Blog assets.
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/assets/asset-report.php') . ' --check --assembly=platform', $exitCode);
exit($exitCode);
