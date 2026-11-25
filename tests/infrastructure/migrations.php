<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

define('TINYCAT', true);
require __DIR__ . '/../../App/Core/Runtime/autoload.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$pdo = \tinycat_test_database();
$assert(!class_exists('TinyCat\\Database\\SchemaInspection'), 'The retired inspection adapter is absent from runtime autoloading');
(require __DIR__ . '/../fixtures/modules/migration-journal.php')($pdo, $assert);
echo "PASS unified migration journal preservation, conflicts and current-baseline enforcement ({$checks} checks)\n";
