<?php
declare(strict_types=1);

use TinyCat\Database\ModuleSchemas;
use TinyCat\Core\Modules\ModuleSchema;

// Reuse the pilot's disposable directory and assertions; no application DB is touched.
$base = $directory . '/fresh-baseline';
$relative = 'App/Modules/Fresh/migrations/';
mkdir($base . '/' . $relative, 0700, true);
$old = '<?php return static function (PDO $pdo): void { file_put_contents(__DIR__ . "/historical-ran", "yes"); $pdo->exec("CREATE TABLE legacy_items (id INTEGER PRIMARY KEY, body TEXT NOT NULL)"); };';
$upgrade = '<?php return static function (PDO $pdo): void { $pdo->exec("ALTER TABLE legacy_items RENAME TO owned_items"); };';
$fresh = '<?php return static function (PDO $pdo): void { $pdo->exec("CREATE TABLE owned_items (id INTEGER PRIMARY KEY, body TEXT NOT NULL)"); };';
foreach (['001.php' => $old, '002.php' => $upgrade, 'fresh-v2.php' => $fresh] as $name => $source) { file_put_contents($base . '/' . $relative . $name, $source); }
$record = static fn (int $version, string $id, string $file): array => ['version' => $version, 'id' => $id, 'file' => $relative . $file, 'checksum' => ModuleSchemas::checksum($base . '/' . $relative . $file)];
$plan = ['format' => 3, 'product' => 'fixture.fresh', 'selection' => ['fixture.fresh' => 'App/Modules/Fresh/module.json'], 'active' => ['fixture.fresh'], 'required' => [], 'requires' => ['fixture.fresh' => []], 'legacy' => false, 'framework' => \TinyCat\Core\Modules\Upgrade::DOMAIN_FRAMEWORK, 'schemaOwners' => ['fixture.fresh' => 'fixture.fresh'], 'schemas' => ['fixture.fresh' => ['version' => 2, 'tables' => ['owned_items'], 'migrations' => [$record(1, 'fixture.first', '001.php'), $record(2, 'fixture.second', '002.php')], 'fresh' => $record(2, 'fixture.fresh-v2', 'fresh-v2.php')]]];
$schemas = \TinyCat\Core\Modules\Distribution::schemas($plan, $base, ['fixture.fresh']);
$newDb = new PDO('sqlite::memory:');
ModuleSchemas::synchronize($newDb, $schemas);
$assert(ModuleSchemas::ready($newDb, $schemas) && !is_file($base . '/' . $relative . 'historical-ran'), 'Signed fresh schema installs directly without replaying obsolete domain tables');
$assert((int) $newDb->query('SELECT COUNT(*) FROM module_migrations')->fetchColumn() === 2, 'Fresh schema records the unchanged historical migration prefix');
$oldDb = new PDO('sqlite::memory:');
$first = new ModuleSchema(1, ['legacy_items'], require $base . '/' . $relative . '001.php', [new \TinyCat\Core\Modules\SchemaMigration(1, 'fixture.first', $base . '/' . $relative . '001.php')]);
ModuleSchemas::install($oldDb, ['fixture.fresh' => $first]);
$oldDb->exec("INSERT INTO legacy_items VALUES (7, 'Retained legacy data')");
ModuleSchemas::synchronize($oldDb, $schemas);
$assert(ModuleSchemas::ready($oldDb, $schemas) && $oldDb->query('SELECT body FROM owned_items WHERE id=7')->fetchColumn() === 'Retained legacy data', 'Existing installation runs historical upgrades and preserves data instead of using the fresh baseline');
$assert($oldDb->query('SELECT * FROM module_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC) === $newDb->query('SELECT * FROM module_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC), 'Fresh and upgrade preserve the same historical migration identities and checksums');
$invalid = $plan; $invalid['schemas']['fixture.fresh']['fresh']['checksum'] = str_repeat('0', 64);
$reject(static fn () => \TinyCat\Core\Modules\Distribution::schemas($invalid, $base, ['fixture.fresh']), 'Fresh baseline checksum mismatch');
$invalid = $plan; $invalid['schemas']['fixture.fresh']['fresh']['file'] = 'App/Modules/Other/migrations/fresh.php';
$reject(static fn () => \TinyCat\Core\Modules\Distribution::validate($invalid), 'Fresh baseline outside its owner');
$invalid = $plan; $invalid['schemas']['fixture.fresh']['fresh']['version'] = 1;
$reject(static fn () => \TinyCat\Core\Modules\Distribution::validate($invalid), 'Fresh baseline with an outdated schema version');
$legacy = '<?php return static function (PDO $pdo): bool { if (!$pdo->query("SELECT name FROM sqlite_master WHERE name = \'legacy_unmanaged\'")->fetchColumn()) { return false; } if ($pdo->query("SELECT proof FROM fixture_origin")->fetchColumn() !== "known-fixture-v1") { throw new RuntimeException("Unknown historical schema"); } $pdo->exec("ALTER TABLE legacy_unmanaged RENAME TO owned_items"); return true; };';
file_put_contents($base . '/' . $relative . 'legacy-v2.php', $legacy);
$plan['schemas']['fixture.fresh']['legacy'] = $record(2, 'fixture.legacy-v2', 'legacy-v2.php');
$legacySchemas = \TinyCat\Core\Modules\Distribution::schemas($plan, $base, ['fixture.fresh']);
$legacyDb = new PDO('sqlite::memory:');
$legacyDb->exec("CREATE TABLE legacy_unmanaged (id INTEGER PRIMARY KEY, body TEXT NOT NULL); CREATE TABLE fixture_origin (proof TEXT); INSERT INTO fixture_origin VALUES ('known-fixture-v1'); INSERT INTO legacy_unmanaged VALUES (9, 'Retained unmanaged data')");
$assert(ModuleSchemas::pending($legacyDb, $legacySchemas), 'An explicit historical conversion is reported as pending without executing its PHP');
ModuleSchemas::synchronize($legacyDb, $legacySchemas);
$assert(ModuleSchemas::ready($legacyDb, $legacySchemas) && $legacyDb->query('SELECT body FROM owned_items WHERE id=9')->fetchColumn() === 'Retained unmanaged data', 'An owner explicitly converts proven historical storage and records its immutable history');
$unknownDb = new PDO('sqlite::memory:');
$unknownDb->exec("CREATE TABLE owned_items (id INTEGER PRIMARY KEY, body TEXT NOT NULL); INSERT INTO owned_items VALUES (11, 'Unknown owner')");
$reject(static fn () => ModuleSchemas::synchronize($unknownDb, $legacySchemas), 'An unrelated existing table is not adopted when the historical converter declines it');
$assert($unknownDb->query('SELECT body FROM owned_items WHERE id=11')->fetchColumn() === 'Unknown owner', 'Rejected adoption preserves unknown owner data');
$emptyDb = new PDO('sqlite::memory:');
ModuleSchemas::synchronize($emptyDb, $legacySchemas);
$assert(ModuleSchemas::ready($emptyDb, $legacySchemas), 'A converter declining an empty installation falls through to its signed fresh schema');
$newDb = null; $oldDb = null;
$legacyDb = null; $unknownDb = null; $emptyDb = null;
