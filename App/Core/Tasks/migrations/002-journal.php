<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    // The running 6.0.4 updater may still have the old ModuleSchemas loaded.
    // Lifecycle finalizes the copy only after that updater records this step.
    \TinyCat\Database\Migrations::prepare($pdo);
};
