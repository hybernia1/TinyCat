<?php
declare(strict_types=1);

$root = $argv[1];
define('TINYCAT', true); require $root . '/App/autoload.php';
\TinyCat\Support\Config::load();
$plan = \TinyCat\Core\Modules\Distribution::read($root);
$state = \TinyCat\Core\Modules\Deployment::read($root);
// Deliberately cache installed providers, including an inactive package owner.
$selection = array_intersect_key($plan['selection'], array_fill_keys($state['installed'], true));
$schemas = \TinyCat\Core\Modules\Loader::fromSelection($root, $selection)->schemas(true);
$cached = $schemas['fixture.addon']->version;
$result = \TinyCat\Core\Modules\ModulePackage::install($argv[2]);
echo json_encode(['cached_version' => $cached, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
