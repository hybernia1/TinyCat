<?php
declare(strict_types=1);

// The supported 6.0.6 baseline already stores every record in migrations.
return static function (\PDO $pdo): void {
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $view = $pdo->query("SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'module_migrations'");
        if ($view === false) { throw new \RuntimeException('Cannot inspect the retired journal view.'); }
        if ($view->fetchColumn() !== false) { $pdo->exec('DROP VIEW module_migrations'); }
    } else {
        $pdo->exec('DROP TRIGGER IF EXISTS module_migrations_insert');
        $pdo->exec('DROP VIEW IF EXISTS module_migrations');
    }
};
