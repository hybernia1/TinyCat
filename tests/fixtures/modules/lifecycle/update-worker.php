<?php
declare(strict_types=1);

$root = $argv[1];
define('TINYCAT', true);
require $root . '/App/bootstrap.php';
// The old active provider is now cached in PHP. The updater must use the signed migration plan.
if (!class_exists(TinyCat\Modules\LifecycleNotes\Module::class)) { throw new RuntimeException('Old provider was not loaded.'); }
$result = TinyCat\Core\Updates\Manager::installLocalPackage($argv[2], $argv[3], $argv[4]);
$result['cached_provider_version'] = TinyCat\Modules\LifecycleNotes\Module::schema()->version;
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
