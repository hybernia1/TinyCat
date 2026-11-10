<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../../../config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP MySQL search: local configuration or pdo_mysql is unavailable.\n";
    exit(0);
}
$argv[] = '--mysql';
require __DIR__ . '/search.php';
