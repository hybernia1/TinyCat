<?php
declare(strict_types=1);

return static function (\PDO $pdo): bool {
    $adopt = require __DIR__ . '/legacy-v1.php';
    if (!$adopt($pdo)) { return false; }
    $upgrade = require __DIR__ . '/002-subjects.php';
    $upgrade($pdo);
    return true;
};
