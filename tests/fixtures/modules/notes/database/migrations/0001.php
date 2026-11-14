<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec('CREATE TABLE IF NOT EXISTS pilot_notes (id ' . $id . ', title VARCHAR(80) NOT NULL, created_by INTEGER NOT NULL)');
};
