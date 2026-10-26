<?php
declare(strict_types=1);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/tools/asset-report.php') . ' --check', $exitCode);
exit($exitCode);
