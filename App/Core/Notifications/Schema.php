<?php
declare(strict_types=1);
namespace TinyCat\Core\Notifications;

/** Fresh inbox schema does not require publication or social tables. */
final class Schema
{
    public static function install(\PDO $pdo): void
    {
        $install = require __DIR__ . '/migrations/fresh-v2.php';
        $install($pdo);
    }

    /** Add opaque producer data without rewriting existing IDs, keys, read state or timestamps. */
    public static function upgrade(\PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $query = $pdo->query($sqlite ? "PRAGMA table_info(notifications)" : "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'");
        if ($query === false) { throw new \RuntimeException('Cannot inspect inbox schema.'); }
        $columns = array_column($query->fetchAll(\PDO::FETCH_ASSOC), 'name');
        if ($columns === []) { return; }
        if (!in_array('payload_json', $columns, true)) { $pdo->exec('ALTER TABLE notifications ADD COLUMN payload_json ' . ($sqlite ? 'TEXT' : 'LONGTEXT') . ' NULL'); }
        // Legacy reference columns remain authoritative for old social types during transition.
        // Existing FK behavior is preserved; the general inbox never queries those subjects.
    }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(2, ['notifications'], \TinyCat\Core\Notifications\Schema::install(...), [new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.notifications.initial', __DIR__ . '/migrations/001-initial.php'), new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.notifications.subjects', __DIR__ . '/migrations/002-subjects.php')], new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.notifications.fresh-v2', __DIR__ . '/migrations/fresh-v2.php'), new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.notifications.legacy-v2', __DIR__ . '/migrations/legacy-v2.php'));
    }
}
