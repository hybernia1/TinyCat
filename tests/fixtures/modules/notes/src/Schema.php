<?php
declare(strict_types=1);

namespace TinyCat\Modules\PilotNotes;

use PDO;
use TinyCat\Core\Modules\ModuleSchema;
use TinyCat\Core\Modules\SchemaMigration;

final class Schema implements \TinyCat\Core\Modules\ProvidesSchemas
{
    public static function schemas(): array
    {
        $root = dirname(__DIR__);
        return ['demo.notes' => new ModuleSchema(2, ['pilot_notes'], static function (PDO $pdo): void {
            $id = 'INTEGER PRIMARY KEY AUTO_INCREMENT';
            $pdo->exec('CREATE TABLE IF NOT EXISTS pilot_notes (id ' . $id . ', title VARCHAR(80) NOT NULL, created_by INTEGER NOT NULL, refresh_count INTEGER NOT NULL DEFAULT 0)');
        }, [
            new SchemaMigration(1, 'demo.notes.0001', $root . '/database/migrations/0001.php'),
            new SchemaMigration(2, 'demo.notes.0002', $root . '/database/migrations/0002.php'),
        ])];
    }
}
