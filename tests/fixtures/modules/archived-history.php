<?php
declare(strict_types=1);

use TinyCat\Core\Modules\{Distribution, ModulePackage, ModuleSchema, SchemaMigration};
use TinyCat\Database\ModuleSchemas;

// Reuses the pilot's disposable databases and bounded working directory.
return static function (PDO $fresh, PDO $upgrade) use ($directory, $assert, $reject): void {
    $driver = (string) $fresh->getAttribute(PDO::ATTR_DRIVER_NAME);
    $base = $directory . '/checkpoint-' . $driver;
    $relative = 'App/Modules/Checkpoint/migrations/';
    mkdir($base . '/' . $relative, 0700, true);
    $sources = [
        '001.php' => '<?php return static function (PDO $pdo): void { $pdo->exec("CREATE TABLE checkpoint_items (id INTEGER PRIMARY KEY, body TEXT NOT NULL)"); };',
        '002.php' => '<?php return static function (PDO $pdo): void { $pdo->exec("ALTER TABLE checkpoint_items ADD COLUMN label VARCHAR(100) NOT NULL DEFAULT \'base\'"); };',
        '003.php' => '<?php return static function (PDO $pdo): void { $pdo->exec("ALTER TABLE checkpoint_items ADD COLUMN revision INTEGER NOT NULL DEFAULT 1"); };',
        'fresh-v3.php' => '<?php return static function (PDO $pdo): void { $pdo->exec("CREATE TABLE checkpoint_items (id INTEGER PRIMARY KEY, body TEXT NOT NULL, label VARCHAR(100) NOT NULL DEFAULT \'base\', revision INTEGER NOT NULL DEFAULT 1)"); };',
    ];
    foreach ($sources as $file => $source) { file_put_contents($base . '/' . $relative . $file, $source); }
    $record = static fn (int $version, string $file): array => ['version' => $version, 'id' => 'fixture.checkpoint.step' . $version, 'file' => $relative . $file, 'checksum' => ModuleSchemas::checksum($base . '/' . $relative . $file)];
    $first = $record(1, '001.php'); $second = $record(2, '002.php');
    $old = [new SchemaMigration(1, $first['id'], $base . '/' . $first['file']), new SchemaMigration(2, $second['id'], $base . '/' . $second['file'])];
    $initial = new ModuleSchema(1, ['checkpoint_items'], require $old[0]->file, [$old[0]]);
    $complete = new ModuleSchema(2, ['checkpoint_items'], static function (PDO $pdo): void {}, $old);
    ModuleSchemas::install($upgrade, ['fixture.checkpoint' => $initial]);
    $upgrade->exec("INSERT INTO checkpoint_items(id, body) VALUES (7, 'Preserved checkpoint data')");
    $plan = ['format' => 4, 'product' => 'fixture.checkpoint', 'selection' => ['fixture.checkpoint' => 'App/Modules/Checkpoint/module.json'],
        'active' => [], 'required' => [], 'requires' => ['fixture.checkpoint' => []], 'legacy' => false,
        'framework' => \TinyCat\Core\Runtime\Framework::CAPABILITIES, 'schemaOwners' => ['fixture.checkpoint' => 'fixture.checkpoint'],
        'schemas' => ['fixture.checkpoint' => ['version' => 3, 'tables' => ['checkpoint_items'],
            'migrations' => [array_replace($first, ['file' => null]), array_replace($second, ['file' => null]), $record(3, '003.php')],
            'fresh' => array_replace($record(3, 'fresh-v3.php'), ['id' => 'fixture.checkpoint.fresh-v3'])]]];
    $schemas = Distribution::schemas($plan, $base, ['fixture.checkpoint']);
    $assert($schemas['fixture.checkpoint']->minimumVersion === 2, $driver . ': Archived prefix defines the minimum installed schema, independent of core version');
    $before = ModuleSchemas::state($upgrade, 'fixture.checkpoint');
    $reject(static fn () => ModuleSchemas::pending($upgrade, $schemas), 'An incomplete checkpoint cannot skip retired data conversions');
    $reject(static fn () => ModuleSchemas::synchronize($upgrade, $schemas), 'Direct synchronization also rejects an unsupported installed prefix');
    $assert(ModuleSchemas::state($upgrade, 'fixture.checkpoint') === $before && $upgrade->query('SELECT body FROM checkpoint_items WHERE id=7')->fetchColumn() === 'Preserved checkpoint data', 'Checkpoint rejection preserves state and data');
    ModuleSchemas::upgrade($upgrade, ['fixture.checkpoint' => $complete]);
    $history = $upgrade->query("SELECT * FROM migrations WHERE stream='fixture.checkpoint' ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC);
    unlink($base . '/' . $first['file']); unlink($base . '/' . $second['file']);
    $schemas = Distribution::schemas($plan, $base, ['fixture.checkpoint']);
    $assert(ModuleSchemas::pending($upgrade, $schemas), 'Missing historical PHP is accepted while a later executable delta is pending');
    ModuleSchemas::synchronize($upgrade, $schemas);
    ModuleSchemas::synchronize($upgrade, $schemas);
    $assert(!ModuleSchemas::pending($upgrade, $schemas) && (int) $upgrade->query('SELECT revision FROM checkpoint_items WHERE id=7')->fetchColumn() === 1, 'Only the remaining delta runs and repeated deployment is safe');
    $after = $upgrade->query("SELECT * FROM migrations WHERE stream='fixture.checkpoint' ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC);
    $assert(array_slice($after, 0, 2) === $history, 'Checkpoint history preserves exact IDs, checksums and original timestamps');
    ModuleSchemas::synchronize($fresh, $schemas);
    $fresh->exec("INSERT INTO checkpoint_items(id, body) VALUES (8, 'Fresh checkpoint data')");
    $assert(ModuleSchemas::ready($fresh, $schemas) && (int) $fresh->query('SELECT revision FROM checkpoint_items WHERE id=8')->fetchColumn() === 1, 'Fresh baseline installs without any retired PHP source');
    $freshHistory = $fresh->query("SELECT migration, sequence, checksum FROM migrations WHERE stream='fixture.checkpoint' ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC);
    $assert($freshHistory === $upgrade->query("SELECT migration, sequence, checksum FROM migrations WHERE stream='fixture.checkpoint' ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC), 'Fresh baseline coverage and updated installation retain identical historical identities');
    $state = ModuleSchemas::state($upgrade, 'fixture.checkpoint');
    $upgrade->exec("UPDATE migrations SET checksum='" . str_repeat('0', 64) . "' WHERE stream='fixture.checkpoint' AND sequence=1");
    $reject(static fn () => ModuleSchemas::pending($upgrade, $schemas), 'Archived database checksum tampering');
    $reject(static fn () => ModuleSchemas::synchronize($upgrade, $schemas), 'Tampered archived history before state mutation');
    $assert(ModuleSchemas::state($upgrade, 'fixture.checkpoint') === $state, 'Failed archival integrity verification preserves the ready state');
    $restore = $upgrade->prepare("UPDATE migrations SET checksum=? WHERE stream='fixture.checkpoint' AND sequence=1"); $restore->execute([$first['checksum']]);
    $invalid = $plan; $invalid['format'] = 3;
    $reject(static fn () => Distribution::validate($invalid), 'Old transport cannot silently accept fileless history');
    $invalid = $plan; $invalid['schemas']['fixture.checkpoint']['migrations'][0]['file'] = $first['file'];
    $reject(static fn () => Distribution::validate($invalid), 'An archive cannot appear after an executable migration');
    $invalid = $plan; unset($invalid['schemas']['fixture.checkpoint']['fresh']);
    $reject(static fn () => Distribution::validate($invalid), 'Archived history requires a standalone executable fresh baseline');
    $invalid = $plan; unset($invalid['schemas']['fixture.checkpoint']['migrations'][0]['file']);
    $reject(static fn () => Distribution::validate($invalid), 'Missing file field is not an archival declaration');
    $invalid = $plan; $invalid['schemas']['fixture.checkpoint']['migrations'][0]['checksum'] = 'invalid';
    $reject(static fn () => Distribution::validate($invalid), 'Malformed archived checksum');
    $invalid = $plan; $invalid['schemas']['fixture.checkpoint']['migrations'][1]['id'] = $first['id'];
    $reject(static fn () => Distribution::validate($invalid), 'Duplicate archived migration identity');
    $package = ['format' => 2, 'kind' => 'tinycat-module', 'id' => 'fixture.checkpoint', 'directory' => 'Checkpoint', 'version' => '1.0.0',
        'minimum_core' => SchemaMigration::ARCHIVE_RUNTIME, 'minimum_php' => '8.4.0', 'manifest' => ['id' => 'fixture.checkpoint', 'coreApi' => 1],
        'schemas' => $plan['schemas'], 'files' => ['module.json' => str_repeat('a', 64)]];
    $assert(ModulePackage::validate($package)['format'] === 2, 'Independent module packages share the archival schema contract');
    $invalid = $package; $invalid['minimum_core'] = '6.0.7';
    $reject(static fn () => ModulePackage::validate($invalid), 'Archive ZIP must require the new reader before changing files');
    $invalid = $package; $invalid['format'] = 1;
    $reject(static fn () => ModulePackage::validate($invalid), 'Old module format rejects fileless history');
    $manifest = ['version' => \TinyCat\Support\Version::CURRENT, 'minimum_version' => '6.0.7', 'minimum_php' => '8.4.0',
        'kind' => 'update', 'package' => 'checkpoint.zip', 'sha256' => str_repeat('a', 64), 'size' => 1024,
        'files' => ['App/distribution.json' => str_repeat('b', 64), $relative . '003.php' => $plan['schemas']['fixture.checkpoint']['migrations'][2]['checksum'],
            $relative . 'fresh-v3.php' => $plan['schemas']['fixture.checkpoint']['fresh']['checksum']], 'migrations' => [], 'delete' => [], 'distribution' => $plan];
    $validate = new ReflectionMethod(\TinyCat\Core\Updates\Manager::class, 'validateManifest');
    $reject(static fn () => $validate->invoke(null, $manifest), 'A signed archive descriptor must not advertise an old updater as compatible');
    $manifest['minimum_version'] = SchemaMigration::ARCHIVE_RUNTIME;
    $assert($validate->invoke(null, $manifest)['distribution']['format'] === 4, 'Checkpoint-aware signed packages validate without historical PHP in their inventory');
    $source = file_get_contents($base . '/' . $relative . '003.php');
    file_put_contents($base . '/' . $relative . '003.php', $source . "\n// changed\n");
    $reject(static fn () => Distribution::schemas($plan, $base, ['fixture.checkpoint']), 'Executable delta checksum remains protected');
};
