<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    // A loaded 6.0.4 lifecycle cannot call a newly added completion hook.
    // Its remaining journal inserts use a view over the one physical journal.
    \TinyCat\Database\Migrations::upgradeWriter($pdo);
};
