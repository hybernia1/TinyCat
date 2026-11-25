<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $id = 'INTEGER PRIMARY KEY AUTO_INCREMENT';
    $pdo->exec('CREATE TABLE IF NOT EXISTS pilot_notes (id ' . $id . ', title VARCHAR(80) NOT NULL, created_by INTEGER NOT NULL)');
};
