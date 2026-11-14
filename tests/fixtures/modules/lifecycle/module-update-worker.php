<?php
declare(strict_types=1);

$root = $argv[1];
define('TINYCAT', true); require $root . '/App/autoload.php';
\TinyCat\Support\Config::load();
$schemas = \TinyCat\Core\Modules\Loader::load($root)->schemas(true);
$cached = $schemas['fixture.addon']->version;
$result = \TinyCat\Core\Modules\ModulePackage::install($argv[2]);
echo json_encode(['cached_version' => $cached, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
