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
        $directory = ($root ?? \TinyCat\Support\Paths::basePath()) . '/App/Core/Tasks/migrations';
        $fresh = $directory . '/fresh-v2.php';
        return new ModuleSchema(2, ['scheduler_runs'], static function (PDO $pdo) use ($fresh): void { (require $fresh)($pdo); }, [new SchemaMigration(1, 'tinycat.core.tasks.history', $directory . '/001-history.php'), new SchemaMigration(2, 'tinycat.core.migrations.unified', $directory . '/002-journal.php')], new SchemaMigration(2, 'tinycat.core.tasks.fresh-v2', $fresh));
    }

    public static function validateLegacyHistory(PDO $pdo): void
    {
        $state = ModuleSchemas::state($pdo, 'tinycat.scheduler');
        if ($state === null) { return; }
        if ((int) $state['version'] !== 1 || $state['status'] !== 'ready') { throw new \RuntimeException('Recover the legacy Scheduler migration before upgrading core history.'); }
        $rows = \TinyCat\Database\Migrations::history($pdo, 'schema', 'tinycat.scheduler');
        if (count($rows) !== 1 || $rows[0]['migration'] !== 'tinycat.scheduler.initial' || (int) $rows[0]['sequence'] !== 1 || !hash_equals(self::LEGACY_CHECKSUM, $rows[0]['checksum'])) { throw new \RuntimeException('Legacy Scheduler migration integrity mismatch.'); }
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
