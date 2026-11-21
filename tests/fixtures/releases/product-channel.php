<?php
declare(strict_types=1);
// Isolated worker: no application configuration or network outside the supplied fixture.
$options = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (isset($options['network'])) {
    $GLOBALS['signedUpdateFixtures'] = $options['network'];
    require __DIR__ . '/github-transport.php';
}
define('TINYCAT', true);
require $options['root'] . '/App/Core/Runtime/autoload.php';
\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration([
    'database' => ['dsn' => 'sqlite::memory:'], 'install' => ['locale' => 'en', 'complete' => false],
    'updates' => ['public_key' => $options['public_key']],
]));
if (isset($options['verify'])) {
    $argv = ['verify-update.php', $options['verify'], '--product=' . $options['product']];
    if (!empty($options['fresh'])) { $argv[] = '--fresh-install'; }
    require dirname(__DIR__, 3) . '/tools/release/verify-update.php';
    exit;
}
\TinyCat\Core\Runtime\Application::start();
$result = \TinyCat\Core\Updates\Manager::check(true);
$blocked = false;
if (!empty($options['reject_install'])) {
    try { \TinyCat\Core\Updates\Manager::installLatest(); }
    catch (RuntimeException $error) { $blocked = str_contains($error->getMessage(), 'An update cannot change the installed product.'); }
}
echo json_encode(['available' => $result['available'], 'compatible' => $result['compatible'], 'product' => $result['manifest']['distribution']['product'], 'blocked' => $blocked, 'installed_product' => \TinyCat\Core\Modules\Distribution::read($options['root'])['product']], JSON_THROW_ON_ERROR), "\n";
