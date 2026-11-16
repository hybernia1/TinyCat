<?php
declare(strict_types=1);

// Standalone DDL also works when the 6.0.5 updater retains its loaded Migrations class.
return static function (\PDO $pdo): void {
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
    $width = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' AND COLUMN_NAME = 'release_version'");
    if ($width === false) { throw new \RuntimeException('Cannot inspect migration version storage.'); }
    $length = (int) $width->fetchColumn();
    if ($length > 0 && $length < 190) { $pdo->exec('ALTER TABLE migrations MODIFY COLUMN release_version VARCHAR(190) NULL'); }
};
