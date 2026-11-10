<?php
declare(strict_types=1);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/assets/asset-report.php') . ' --check', $exitCode);
exit($exitCode);
