<?php
declare(strict_types=1);

return static function (\PDO $pdo): bool {
    $adopt = require __DIR__ . '/legacy-v2.php';
    if (!$adopt($pdo)) { return false; }
    $convert = require __DIR__ . '/003-notification-subjects.php'; $convert($pdo);
    return true;
};
