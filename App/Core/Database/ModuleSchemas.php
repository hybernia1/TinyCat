<?php
declare(strict_types=1);

namespace TinyCat\Database;

use PDO;
use RuntimeException;
use TinyCat\Core\Modules\ModuleSchema;
use TinyCat\Core\Modules\SchemaMigration;

/** Explicit install/upgrade operations. Discovery and HTTP boot never call these. */
final class ModuleSchemas
{
    /** Verify the installed prefix before any deployment mutation.
     * @param array<string, ModuleSchema> $schemas */
    public static function pending(PDO $pdo, array $schemas): bool
    {
        self::validate($schemas); $pending = false;
        foreach ($schemas as $id => $schema) {
            $state = self::state($pdo, $id);
            if ($state === null) {
                foreach ($schema->tables as $table) { if ($schema->legacy === null && self::tableExists($pdo, $table)) { throw new RuntimeException('Refusing to adopt an unmanaged table: ' . $table); } }
                $pending = true; continue;
            }
            $version = (int) $state['version'];
            $retired = $state['status'] === 'retired' && $schema->legacy !== null && $version < $schema->version;
            if ($state['status'] !== 'ready' && !$retired) { throw new RuntimeException('Recover the interrupted module operation before continuing: ' . $id); }
            if ($version > $schema->version) { throw new RuntimeException('Module schema downgrade is not supported.'); }
            self::supported($id, $schema, $version);
            self::verifyHistory($pdo, $id, $schema, $version);
            if ($version < $schema->version) { $pending = true; }
            elseif (!self::ready($pdo, [$id => $schema])) { throw new RuntimeException('Installed module tables are missing: ' . $id); }
        }
        return $pending;
    }

    /** Install or upgrade a signed migration plan without loading updated provider classes.
     * @param array<string, ModuleSchema> $schemas */
    public static function synchronize(PDO $pdo, array $schemas): void
    {
        self::validate($schemas);
        foreach ($schemas as $id => $schema) {
            if (self::state($pdo, $id) !== null) { self::upgrade($pdo, [$id => $schema]); continue; }
            if ($schema->legacy !== null && self::convertLegacy($pdo, $id, $schema)) { continue; }
            $fresh = new ModuleSchema($schema->version, $schema->tables, static function (PDO $database) use ($schema): void {
                if ($schema->baseline !== null) { self::applyFile($database, $schema->baseline); }
                else { foreach ($schema->migrations as $migration) { self::applyFile($database, $migration); } }
            }, $schema->migrations, $schema->baseline);
            self::install($pdo, [$id => $fresh]);
        }
    }

    private static function applyFile(PDO $pdo, SchemaMigration $migration): void
    {
        if ($migration->file === null) { throw new RuntimeException('Archived migrations cannot be executed.'); }
        $checksum = self::migrationChecksum($migration);
        $apply = require $migration->file;
        if (!$apply instanceof \Closure) { throw new RuntimeException('Module migration must return a closure.'); }
        $apply($pdo);
        if (!hash_equals($checksum, self::checksum($migration->file))) { throw new RuntimeException('Module migration changed during execution.'); }
    }

    /** Only explicit, verified deployment callbacks may adopt historical domain storage. */
    private static function convertLegacy(PDO $pdo, string $id, ModuleSchema $schema): bool
    {
        $migration = $schema->legacy ?? throw new RuntimeException('Missing legacy conversion.');
        $file = $migration->file ?? throw new RuntimeException('Legacy conversion requires executable source.');
        $checksum = self::migrationChecksum($migration); $apply = require $file;
        if (!$apply instanceof \Closure) { throw new RuntimeException('Legacy conversion must return a closure.'); }
        $converted = $apply($pdo);
        if (!is_bool($converted) || !hash_equals($checksum, self::checksum($file))) { throw new RuntimeException('Invalid legacy conversion result or changed source.'); }
        if (!$converted) { return false; }
        foreach ($schema->tables as $table) { if (!self::tableExists($pdo, $table)) { throw new RuntimeException('Legacy conversion did not create the declared table: ' . $table); } }
        self::systemTables($pdo);
        foreach ($schema->migrations as $history) { self::record($pdo, $id, $history); }
        self::writeState($pdo, $id, $schema->version, 'ready');
        return true;
    }

    /** @param array<string, ModuleSchema> $schemas */
    public static function ready(PDO $pdo, array $schemas): bool
    {
        if ($schemas === []) { return true; }
        $tables = ['module_installations'];
        foreach ($schemas as $schema) { array_push($tables, ...$schema->tables); }
        $existing = array_fill_keys(Schema::existing($pdo, $tables), true);
        foreach ($tables as $table) { if (!isset($existing[$table])) { return false; } }
        $states = [];
        foreach (array_chunk(array_keys($schemas), 500) as $ids) {
            $query = \TinyCat\Core\Database\Statements::query($pdo,
                'SELECT module_id, version, status FROM module_installations WHERE module_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $states[(string) $row['module_id']] = $row; }
        }
        foreach ($schemas as $id => $schema) {
            $state = $states[$id] ?? null;
            if ($state === null || $state['status'] !== 'ready' || (int) $state['version'] !== $schema->version) { return false; }
        }
        return true;
    }

    /** @param array<string, ModuleSchema> $schemas */
    public static function install(PDO $pdo, array $schemas): void
    {
        if ($schemas === []) { return; }
        self::connection($pdo);
        self::validate($schemas);
        self::systemTables($pdo);
        foreach ($schemas as $id => $schema) {
            self::locked($pdo, $id, static function () use ($pdo, $id, $schema): void {
                $state = self::state($pdo, $id);
                if ($state !== null) {
                    if ($state['status'] === 'ready' && (int) $state['version'] === $schema->version && self::ready($pdo, [$id => $schema])) { self::verifyHistory($pdo, $id, $schema, $schema->version); return; }
                    throw new RuntimeException('Module is already installed; use upgrade or recover its backup: ' . $id);
                }
                foreach ($schema->tables as $table) {
                    if (self::tableExists($pdo, $table)) { throw new RuntimeException('Refusing to adopt an unmanaged table: ' . $table); }
                }
                self::writeState($pdo, $id, 0, 'installing');
                try {
                    ($schema->install)($pdo);
                    foreach ($schema->tables as $table) { if (!self::tableExists($pdo, $table)) { throw new RuntimeException('Module install did not create its declared table: ' . $table); } }
                    foreach ($schema->migrations as $migration) { self::record($pdo, $id, $migration); }
                    self::writeState($pdo, $id, $schema->version, 'ready');
                } catch (\Throwable $error) { self::writeState($pdo, $id, 0, 'failed'); throw $error; }
            });
        }
    }

    /** @param array<string, ModuleSchema> $schemas */
    public static function upgrade(PDO $pdo, array $schemas): void
    {
        if ($schemas === []) { return; }
        self::connection($pdo);
        self::validate($schemas);
        self::systemTables($pdo);
        foreach ($schemas as $id => $schema) {
            self::locked($pdo, $id, static function () use ($pdo, $id, $schema): void {
                $state = self::state($pdo, $id);
                if ($state === null) { throw new RuntimeException('Module is not installed: ' . $id); }
                $version = (int) $state['version'];
                if ($version > $schema->version) { throw new RuntimeException('Module schema downgrade is not supported.'); }
                if ($version !== 0) { self::supported($id, $schema, $version); }
                elseif ($schema->minimumVersion > 0 && $state['status'] !== 'installing' && $state['status'] !== 'failed') { throw new RuntimeException('Invalid interrupted fresh installation state.'); }
                self::verifyHistory($pdo, $id, $schema, $version);
                // A retired owner's explicit migration must observe the retained
                // retirement marker before creating its independent storage.
                if ($state['status'] === 'retired') {
                    if ($schema->legacy === null || $version >= $schema->version) { throw new RuntimeException('No supported retired-owner conversion: ' . $id); }
                } else { self::writeState($pdo, $id, $version, 'upgrading'); }
                try {
                    if ($version === 0 && $schema->baseline !== null) {
                        foreach ($schema->tables as $table) {
                            if (self::tableExists($pdo, $table)) { throw new RuntimeException('Restore the interrupted fresh installation before retrying: ' . $id); }
                        }
                        self::applyFile($pdo, $schema->baseline);
                        foreach ($schema->migrations as $migration) { self::record($pdo, $id, $migration); }
                        $version = $schema->version;
                    }
                    foreach ($schema->migrations as $migration) {
                        if ($migration->version <= $version) { continue; }
                        self::applyFile($pdo, $migration);
                        self::record($pdo, $id, $migration);
                        $version = $migration->version;
                        self::writeState($pdo, $id, $version, 'upgrading');
                    }
                    foreach ($schema->tables as $table) { if (!self::tableExists($pdo, $table)) { throw new RuntimeException('Module upgrade lost a declared table: ' . $table); } }
                    self::writeState($pdo, $id, $schema->version, 'ready');
                } catch (\Throwable $error) { self::writeState($pdo, $id, $version, 'failed'); throw $error; }
            });
        }
    }

    public static function checksum(string $file): string
    {
        return Migrations::checksum($file);
    }

    public static function migrationChecksum(SchemaMigration $migration): string
    {
        if ($migration->file === null) { return $migration->checksum ?? throw new RuntimeException('Missing archived migration checksum.'); }
        $checksum = self::checksum($migration->file);
        if ($migration->checksum !== null && !hash_equals($migration->checksum, $checksum)) { throw new RuntimeException('Migration source does not match its declared checksum.'); }
        return $checksum;
    }

    private static function supported(string $id, ModuleSchema $schema, int $version): void
    {
        if ($version < $schema->minimumVersion) { throw new RuntimeException('Module ' . $id . ' requires completed schema version ' . $schema->minimumVersion . '; apply the checkpoint release first.'); }
    }

    /** @return array<string, mixed>|null */
    public static function state(PDO $pdo, string $id): ?array
    {
        if (!self::tableExists($pdo, 'module_installations')) { return null; }
        $query = $pdo->prepare('SELECT version, status FROM module_installations WHERE module_id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, ModuleSchema> $schemas */
    private static function validate(array $schemas): void
    {
        $tables = []; $ids = [];
        foreach ($schemas as $id => $schema) {
            if (preg_match('/^[a-z][a-z0-9.-]+$/D', $id) !== 1) { throw new RuntimeException('Invalid schema owner.'); }
            foreach ($schema->tables as $table) {
                if (isset($tables[$table])) { throw new RuntimeException('Duplicate module table owner: ' . $table); }
                $tables[$table] = $id;
            }
            foreach ($schema->migrations as $migration) {
                if (isset($ids[$migration->id])) { throw new RuntimeException('Duplicate migration ID: ' . $migration->id); }
                $ids[$migration->id] = true;
                self::migrationChecksum($migration);
            }
            foreach ([$schema->baseline, $schema->legacy] as $operation) { if ($operation !== null) { self::migrationChecksum($operation); } }
        }
    }

    private static function systemTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS module_installations (module_id VARCHAR(190) PRIMARY KEY, version INTEGER NOT NULL, status VARCHAR(20) NOT NULL)');
        Migrations::prepare($pdo);
    }

    private static function connection(PDO $pdo): void
    {
        if (!in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'sqlite'], true) || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) { throw new RuntimeException('Module schemas require MySQL/SQLite with PDO exceptions enabled.'); }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        return Schema::exists($pdo, $table);
    }

    private static function writeState(PDO $pdo, string $id, int $version, string $status): void
    {
        $query = $pdo->prepare('SELECT 1 FROM module_installations WHERE module_id = ?');
        $query->execute([$id]);
        $exists = $query->fetchColumn() !== false;
        $query = $pdo->prepare($exists ? 'UPDATE module_installations SET version = ?, status = ? WHERE module_id = ?' : 'INSERT INTO module_installations (version, status, module_id) VALUES (?, ?, ?)');
        $query->execute([$version, $status, $id]);
    }

    private static function record(PDO $pdo, string $id, SchemaMigration $migration): void
    {
        Migrations::record($pdo, 'schema', $id, $migration->id, $migration->version, null, self::migrationChecksum($migration));
    }

    private static function verifyHistory(PDO $pdo, string $id, ModuleSchema $schema, int $version): void
    {
        $rows = Migrations::history($pdo, 'schema', $id);
        usort($rows, static fn (array $left, array $right): int => (int) $left['sequence'] <=> (int) $right['sequence']);
        if (count($rows) !== $version) { throw new RuntimeException('Module migration history is incomplete.'); }
        foreach ($rows as $index => $row) {
            $migration = $schema->migrations[$index];
            if ($row['migration'] !== $migration->id || (int) $row['sequence'] !== $migration->version || !hash_equals((string) $row['checksum'], self::migrationChecksum($migration))) { throw new RuntimeException('Applied module migration checksum or ID mismatch.'); }
        }
    }

    private static function locked(PDO $pdo, string $id, callable $action): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($pdo->inTransaction()) { throw new RuntimeException('Module schema changes require a dedicated connection outside a transaction.'); }
        $database = '';
        if ($mysql) { $query = $pdo->prepare('SELECT DATABASE()'); $query->execute(); $database = (string) $query->fetchColumn(); }
        $name = 'tc-module-' . substr(hash('sha256', $database . ':' . $id), 0, 40);
        if ($mysql) {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute([$name]);
            if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('Module schema operation is already running.'); }
        } else { $pdo->exec('BEGIN IMMEDIATE'); }
        try { $action(); if (!$mysql) { $pdo->exec('COMMIT'); } }
        catch (\Throwable $error) { if (!$mysql) { $pdo->exec('ROLLBACK'); } throw $error; }
        finally { if ($mysql) { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$name]); } }
    }
}
