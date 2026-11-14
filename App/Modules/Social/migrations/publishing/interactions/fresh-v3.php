<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $install = require __DIR__ . '/fresh-v2.php'; $install($pdo);
    $convert = require __DIR__ . '/003-notification-subjects.php'; $convert($pdo);
};
