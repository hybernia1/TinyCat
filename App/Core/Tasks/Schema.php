<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

use PDO;
use TinyCat\Core\Modules\{ModuleSchema, SchemaMigration};
use TinyCat\Database\ModuleSchemas;

/** Core owns execution storage; the Scheduler module only presents it. */
final class Schema
{
    public const OWNER = 'tinycat.support';
    public const LEGACY_CHECKSUM = 'caeaae1eb91853db1a83e73afd2e17830e79877dae46b1989bcaf8c2e59f130d';

    public static function schema(?string $root = null): ModuleSchema
    {
        $file = ($root ?? \TinyCat\Support\Paths::basePath()) . '/App/Core/Tasks/migrations/001-history.php';
        return new ModuleSchema(1, ['scheduler_runs'], static function (PDO $pdo) use ($file): void { (require $file)($pdo); }, [new SchemaMigration(1, 'tinycat.core.tasks.history', $file)]);
    }

    public static function validateLegacyHistory(PDO $pdo): void
    {
        $state = ModuleSchemas::state($pdo, 'tinycat.scheduler');
        if ($state === null) { return; }
        if ((int) $state['version'] !== 1 || $state['status'] !== 'ready') { throw new \RuntimeException('Recover the legacy Scheduler migration before upgrading core history.'); }
        $query = $pdo->prepare('SELECT migration, version, checksum FROM module_migrations WHERE module_id = ? ORDER BY version');
        $query->execute(['tinycat.scheduler']); $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || $rows[0]['migration'] !== 'tinycat.scheduler.initial' || (int) $rows[0]['version'] !== 1 || !hash_equals(self::LEGACY_CHECKSUM, $rows[0]['checksum'])) { throw new \RuntimeException('Legacy Scheduler migration integrity mismatch.'); }
    }

    /** Only the known infrastructure table can cross this ownership boundary. */
    public static function mayAdopt(PDO $pdo, string $owner, ModuleSchema $schema): bool
    {
        if ($owner !== self::OWNER || $schema->tables !== ['scheduler_runs'] || ($schema->migrations[0]->id ?? '') !== 'tinycat.core.tasks.history') { return false; }
        self::validateLegacyHistory($pdo);
        $query = $pdo->query($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'PRAGMA table_info(scheduler_runs)' : "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduler_runs'");
        if ($query === false) { throw new \RuntimeException('Cannot inspect core task history.'); }
        $columns = array_column($query->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (array_diff(['id', 'task', 'mode', 'actor_id', 'status', 'started_at', 'finished_at', 'duration_ms', 'options_json', 'summary_json', 'error'], $columns) !== []) { throw new \RuntimeException('Existing core task history has an incompatible schema.'); }
        return true;
    }
}
