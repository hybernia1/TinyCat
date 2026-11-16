<?php
declare(strict_types=1);

define('TINYCAT', true);
require __DIR__ . '/../../App/autoload.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(require __DIR__ . '/../fixtures/modules/migration-journal.php')($pdo, $assert);
echo "PASS unified migration journal preservation, conflicts and interruption recovery ({$checks} checks)\n";
