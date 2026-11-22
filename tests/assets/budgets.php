<?php
declare(strict_types=1);
// Public website settings add editor labels to admin scopes; access profiles have separately measured budgets.
// The distributed superset must fit the existing budgets, including optional module assets.
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/assets/asset-report.php') . ' --check --assembly=platform', $exitCode);
exit($exitCode);
