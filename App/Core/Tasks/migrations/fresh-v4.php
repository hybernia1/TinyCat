<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    (require __DIR__ . '/fresh-v3.php')($pdo);
    (require __DIR__ . '/004-release-labels.php')($pdo);
};
