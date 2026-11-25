<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require_once __DIR__ . '/../../support/cache-isolation.php';
$source = dirname(__DIR__, 3); $root = TinyCat\Tools\Workspace::directory('tests/file-recovery-' . bin2hex(random_bytes(6)));
$copy = static function (string $from, string $to) use (&$copy): void { if (is_file($from)) { copy($from, $to); return; } if (!is_dir($to)) { mkdir($to, 0700, true); } foreach (scandir($from) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($from . '/' . $entry, $to . '/' . $entry); } } };
$copy($source . '/App', $root . '/App'); mkdir($root . '/App/Modules/FileFixture/src', 0700, true); copy($source . '/tests/fixtures/files/Module.php', $root . '/App/Modules/FileFixture/src/Module.php');
$manifest = ['format' => 1, 'coreApi' => 1, 'id' => 'fixture.files', 'namespace' => 'TinyCat\\Fixture\\Files\\', 'requires' => [], 'providers' => ['services' => 'TinyCat\\Fixture\\Files\\Module', 'routes' => 'TinyCat\\Fixture\\Files\\Module', 'contributions' => 'TinyCat\\Fixture\\Files\\Module'], 'resources' => new stdClass(), 'distribution' => ['product' => 'fixture.files', 'legacy' => false, 'required' => [], 'artifact' => 'fixture']];
file_put_contents($root . '/App/Modules/FileFixture/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));
define('TINYCAT', true); require $root . '/App/Core/Runtime/autoload.php';
use TinyCat\Core\Modules\{Loader, Distribution, Deployment, Resources, Registrations};
use TinyCat\Core\Tasks\Cleanup;
use TinyCat\Core\Ui\Branding;
use TinyCat\Database\Connection;
(new ReflectionProperty(TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(TinyCat\Support\Config::class, 'config'))->setValue(null, ['datetime' => ['timezone' => 'UTC']]);
$checks = 0; $assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$db = \tinycat_test_database(); Connection::set($db);
TinyCat\Core\Identity\Schema::install($db); TinyCat\Core\Settings\Schema::install($db);
$selection = ['fixture.files' => 'App/Modules/FileFixture/module.json', 'tinycat.social' => 'App/Modules/Social/module.json'];
$all = Loader::fromSelection($root, $selection); Resources::configure($all->catalog);
$plan = Distribution::describe($root, 'fixture.files', $selection, []); file_put_contents($root . '/App/distribution.json', json_encode($plan, JSON_THROW_ON_ERROR));
$activate = static function (array $active, array $installed) use ($root, $plan, $selection): void {
    Deployment::write($root, $plan, $active, $installed, '8.0.0');
    $loader = Loader::fromSelection($root, array_intersect_key($selection, array_fill_keys($active, true))); Resources::configure($loader->catalog); Registrations::configure($loader->registries());
};
$activate([], ['fixture.files', 'tinycat.social']);
TinyCat\Social\Posts\Schema::install($db);
$db->exec('CREATE TABLE fixture_files(path TEXT)'); $db->exec("INSERT INTO fixture_files VALUES ('retained.bin')");
mkdir($root . '/uploads/fixture', 0700, true);
foreach (['retained.bin', 'orphan.bin', 'fresh.bin'] as $name) { file_put_contents($root . '/uploads/fixture/' . $name, $name); if ($name !== 'fresh.bin') { touch($root . '/uploads/fixture/' . $name, time() - 7200); } }
$ids = Cleanup::cron_cleanup_tasks();
$assert(in_array('fixture_file_orphans', $ids, true) && !in_array('fixture_active_only', $ids, true), 'Installed inactive owners contribute only opted-in file recovery');
Cleanup::cleanup_task_run('fixture_file_orphans', 500); Cleanup::cleanup_task_run('fixture_file_orphans', 500);
$assert(is_file($root . '/uploads/fixture/retained.bin') && is_file($root . '/uploads/fixture/fresh.bin') && !is_file($root . '/uploads/fixture/orphan.bin'), 'Repeated inactive recovery protects references and active preparation');
$activate(['fixture.files'], ['fixture.files', 'tinycat.social']);
$ids = Cleanup::cron_cleanup_tasks(); $assert(count(array_filter($ids, static fn (string $id): bool => $id === 'fixture_file_orphans')) === 1 && in_array('fixture_active_only', $ids, true), 'Active contributions are deduplicated and keep default tasks');
$activate([], ['tinycat.social']); $assert(!in_array('fixture_file_orphans', Cleanup::cron_cleanup_tasks(), true), 'Uninstalled sources do not execute even while their folder remains');
$activate([], ['fixture.files', 'tinycat.social']);
file_put_contents($root . '/uploads/fixture/protected.bin', 'do not lose'); touch($root . '/uploads/fixture/protected.bin', time() - 7200);
$provider = $root . '/App/Modules/FileFixture/src/Module.php'; rename($provider, $provider . '.missing');
$rejected = false;
try { Cleanup::cleanup_task_run('fixture_file_orphans', 500); }
catch (Throwable) { $rejected = true; }
$assert($rejected && is_file($root . '/uploads/fixture/protected.bin'), 'Even a previously loaded missing provider stops before unlink');
rename($provider . '.missing', $provider);
$manifestPath = $root . '/App/Modules/FileFixture/module.json'; rename($manifestPath, $manifestPath . '.missing');
$rejected = false;
try { Cleanup::cleanup_task_run('orphan_status_image_files', 500); }
catch (Throwable) { $rejected = true; }
$assert($rejected && is_file($root . '/uploads/fixture/protected.bin'), 'An incomplete owner map never partially deletes');
rename($manifestPath . '.missing', $manifestPath);
// Real Social orphan recovery remains available with Social inactive.
mkdir($root . '/uploads/status-images/2026/11', 0700, true);
$retained = '2026/11/' . str_repeat('a', 32) . '.webp'; $orphan = '2026/11/' . str_repeat('b', 32) . '.webp';
$db->exec("INSERT INTO users (id, username) VALUES (1, 'retained')");
$id = TinyCat\Core\Modules\ModuleAutoload::withCatalog($all->catalog, static fn (): int => TinyCat\Social\Posts\PostCreation::create(1, TinyCat\Social\Posts\PostCreation::fromText('Retained'), [], ['path' => $retained, 'width' => 1, 'height' => 1, 'bytes' => 1]));
foreach ([$retained, $orphan] as $path) { file_put_contents($root . '/uploads/status-images/' . $path, 'image'); touch($root . '/uploads/status-images/' . $path, time() - 7200); }
file_put_contents($root . '/uploads/status-images/2026/11/.image-abandoned', 'abandoned preparation');
touch($root . '/uploads/status-images/2026/11/.image-abandoned', time() - 172801);
file_put_contents($root . '/uploads/status-images/2026/11/.image-active', 'active preparation');
Cleanup::cleanup_task_run('orphan_status_image_files', 500);
$assert(is_file($root . '/uploads/status-images/' . $retained) && !is_file($root . '/uploads/status-images/' . $orphan), 'Inactive Social still protects its current data while recovering abandoned files');
$assert(!is_file($root . '/uploads/status-images/2026/11/.image-abandoned') && is_file($root . '/uploads/status-images/2026/11/.image-active'), 'Inactive owner recovery also removes aged preparations while preserving active ones');
// Branding orphan sets include variants left by an interrupted preparation.
$directory = $root . '/uploads/site/2026/11'; mkdir($directory, 0700, true);
$set = static function (string $stem, bool $fresh = false) use ($directory): void { foreach (['.webp', '-32.png', '-apple-touch.png', '-192.png', '-512.png', '-maskable-512.png'] as $suffix) { file_put_contents($directory . '/' . $stem . $suffix, 'raster'); if (!$fresh) { touch($directory . '/' . $stem . $suffix, time() - 7200); } } };
$set('retained'); $set('retained-maskable'); $set('orphan'); $set('fresh', true); $set('partial'); unlink($directory . '/partial.webp');
TinyCat\Core\Settings\Store::setSetting('site.favicon_path', '2026/11/retained.webp');
TinyCat\Core\Settings\Store::setSetting('site.logo_path', '2026/11/retained-maskable.webp');
Branding::cleanupOrphans(500); Branding::cleanupOrphans(500);
$assert(is_file($directory . '/retained.webp') && is_file($directory . '/retained-512.png') && is_file($directory . '/retained-maskable-512.png') && is_file($directory . '/retained-maskable-maskable-512.png') && is_file($directory . '/fresh.webp'), 'Branding scan preserves complete referenced sets and active preparation');
$assert(!is_file($directory . '/orphan.webp') && !is_file($directory . '/orphan-32.png') && !is_file($directory . '/partial-512.png') && !is_file($directory . '/partial-maskable-512.png'), 'Branding scan recovers whole orphan and incomplete sets');
$set('bounded');
for ($attempt = 0; $attempt < 60; $attempt++) {
    $batch = Branding::cleanupOrphans(1);
    $assert($batch['changed'] <= 1, 'Branding recovery honors even a one-file batch while deleting variants');
    if (!is_file($directory . '/bounded-maskable-512.png') && !is_file($directory . '/bounded.webp') && !$batch['has_more']) { break; }
}
$assert(!is_file($directory . '/bounded.webp') && !is_file($directory . '/bounded-maskable-512.png'), 'Small checkpointed batches eventually recover the complete orphan set');
$assert(($GLOBALS['fixture_regular_runs'] ?? 0) === 0, 'Loading inactive definitions never executes regular maintenance callbacks');
echo "PASS installed inactive file owners and branding recovery ($checks checks); workspace: $root\n";
