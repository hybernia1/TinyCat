<?php
declare(strict_types=1);

// The surrounding lifecycle fixture owns every path, database and rollback here.
$addon = $candidate . '/App/Modules/Addon';
$oldSource = (string) file_get_contents($addon . '/migrations/1.php');
[$exit, $text, $error] = $run([PHP_BINARY, '-r', 'define("TINYCAT", true); require "App/Core/Runtime/autoload.php"; echo TinyCat\\Database\\ModuleSchemas::checksum("App/Modules/Addon/migrations/1.php");'], $candidate);
$assert($exit === 0 && preg_match('/^[a-f0-9]{64}$/D', trim($text)) === 1, 'Read the published checksum through the candidate migration mechanism: ' . $error);
$oldChecksum = trim($text);
$source = (string) file_get_contents($addon . '/src/Module.php');
$start = strpos($source, '    public static function schema():');
$end = strpos($source, '    public static function contributions():', $start);
if ($start === false || $end === false) { throw new RuntimeException('Invalid archive fixture provider.'); }
$replacement = <<<'PHP'
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        $root = dirname(__DIR__);
        $fresh = new \TinyCat\Core\Modules\SchemaMigration(2, 'fixture.addon.fresh-v2', $root . '/migrations/fresh-v2.php');
        return new \TinyCat\Core\Modules\ModuleSchema(2, ['lifecycle_addon'], static function (\PDO $pdo) use ($root): void {
            $apply = require $root . '/migrations/fresh-v2.php'; $apply($pdo);
        }, [new \TinyCat\Core\Modules\SchemaMigration(1, 'fixture.addon.1', null, '@@CHECKSUM@@'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'fixture.addon.2', $root . '/migrations/2.php')], $fresh);
    }

PHP;
file_put_contents($addon . '/src/Module.php', substr($source, 0, $start) . str_replace('@@CHECKSUM@@', $oldChecksum, $replacement) . substr($source, $end));
file_put_contents($addon . '/migrations/fresh-v2.php', str_replace('ticks INTEGER NOT NULL DEFAULT 0)', 'ticks INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 2)', $oldSource));
unlink($addon . '/migrations/1.php');
$archiveZip = $workspace . '/module-archive/fixture.addon-2.1.0.zip';
[$exit, $text, $error] = $run([PHP_BINARY, $candidate . '/tools/release/build-module.php', '--module=App/Modules/Addon', '--version=2.1.0', '--minimum-core=' . $versions[1], '--output=' . $workspace . '/module-archive'], $candidate);
$assert($exit === 0, 'Build a module with retired executable history: ' . $text . $error);
$zip = new ZipArchive(); $zip->open($archiveZip); $metadata = json_decode($zip->getFromName('package.json'), true);
$assert($metadata['format'] === 2 && $metadata['schemas']['fixture.addon']['migrations'][0]['file'] === null && $zip->locateName('migrations/1.php') === false, 'Archive ZIP contains history metadata and fresh schema without retired PHP');
$zip->close();
$beforeHistory = $pdo->query("SELECT * FROM migrations WHERE stream='fixture.addon' ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC);
foreach (['checksum', 'checkpoint'] as $attack) {
    $bad = $workspace . '/bad-archive-' . $attack . '.zip'; copy($archiveZip, $bad);
    $zip = new ZipArchive(); $zip->open($bad); $meta = json_decode($zip->getFromName('package.json'), true);
    if ($attack === 'checksum') { $meta['schemas']['fixture.addon']['migrations'][0]['checksum'] = str_repeat('0', 64); }
    else { $meta['schemas']['fixture.addon']['migrations'][1]['file'] = null; }
    $zip->addFromString('package.json', json_encode($meta)); $zip->close();
    [$exit] = $console(['module-install', $bad]);
    $assert($exit !== 0 && file_get_contents($root . '/App/module-packages.json') === $registryBytes && hash_file('sha256', $root . '/App/Modules/Addon/src/Module.php') === $addonHash && !is_file($root . '/storage/maintenance.json'), 'Rejected archive leaves files, registry and maintenance unchanged: ' . $attack);
}
[$exit, $text, $error] = $console(['deactivate', 'fixture.addon']);
$assert($exit === 0, 'Archive fixture can upgrade an inactive installed owner: ' . $text . $error);
[$exit, $text, $error] = $run([PHP_BINARY, $fixture . '/module-update-worker.php', $root, $archiveZip], $root);
$assert($exit === 0 && (json_decode($text, true)['cached_version'] ?? null) === 1 && (int) $pdo->query('SELECT revision FROM lifecycle_addon')->fetchColumn() === 2 && !is_file($root . '/App/Modules/Addon/migrations/1.php'), 'Cached provider and inactive owner use metadata history while the remaining delta runs: ' . $text . $error);
$assert($pdo->query("SELECT * FROM migrations WHERE stream='fixture.addon' AND sequence=1")->fetch(PDO::FETCH_ASSOC) === $beforeHistory[0], 'Archive upgrade preserves the original journal row and timestamp exactly');
$operation = json_decode(file_get_contents($root . '/storage/updates/operation.json'), true);
[$exit, $text, $error] = $console(['rollback', $operation['backup']]);
$assert($exit === 0 && file_get_contents($root . '/App/module-packages.json') === $registryBytes && hash_file('sha256', $root . '/App/Modules/Addon/src/Module.php') === $addonHash && is_file($root . '/App/Modules/Addon/migrations/1.php') && $pdo->query("SELECT * FROM migrations WHERE stream='fixture.addon' ORDER BY sequence")->fetchAll(PDO::FETCH_ASSOC) === $beforeHistory, 'Standard rollback restores retired PHP, catalog bytes and exact archived history: ' . $text . $error);
[$exit, $text, $error] = $console(['activate', 'fixture.addon']);
$assert($exit === 0, 'Restore the fixture activation after archive rollback: ' . $text . $error);
