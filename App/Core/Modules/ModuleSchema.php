<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use Closure;
use PDO;

final readonly class ModuleSchema
{
    /** @var Closure(PDO): void */
    public Closure $install;

    /** @param list<string> $tables
     * @param callable(PDO): void $install Current fresh schema and idempotent seeds.
     * @param list<SchemaMigration> $migrations */
    public function __construct(public int $version, public array $tables, callable $install, public array $migrations = [], public ?SchemaMigration $baseline = null, public ?SchemaMigration $legacy = null)
    {
        if ($version < 1 || $tables === [] || count($tables) !== count(array_unique($tables))) { throw new \InvalidArgumentException('Invalid module schema version or tables.'); }
        foreach ($tables as $table) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1 || in_array($table, ['schema_migrations', 'module_installations', 'module_migrations'], true)) { throw new \InvalidArgumentException('Invalid or reserved module table: ' . $table); }
        }
        foreach ($migrations as $index => $migration) {
            if ($migration->version !== $index + 1 || preg_match('/^[a-z][a-z0-9_.-]+$/D', $migration->id) !== 1) { throw new \InvalidArgumentException('Module migrations must have stable IDs and contiguous versions.'); }
        }
        if (count($migrations) !== $version) { throw new \InvalidArgumentException('Module migration history must reach the current schema version.'); }
        if ($baseline !== null && $baseline->version !== $version) { throw new \InvalidArgumentException('Fresh schema must match the current schema version.'); }
        if ($legacy !== null && $legacy->version !== $version) { throw new \InvalidArgumentException('Legacy conversion must match the current schema version.'); }
        $this->install = Closure::fromCallable($install);
    }
}
