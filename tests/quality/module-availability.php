<?php
declare(strict_types=1);

// Run against a copied application: the real installation and its modules are untouched.
if (($argv[1] ?? '') !== '--isolated') {
    require_once __DIR__ . '/../../tools/support/workspace.php';
    $source = dirname(__DIR__, 2);
    $fixture = \TinyCat\Tools\Workspace::directory('tests/tmp') . '/module-availability-' . bin2hex(random_bytes(6));
    $copy = static function (string $from, string $to) use (&$copy): void {
        if (is_file($from)) { copy($from, $to); return; }
        mkdir($to, 0700, true);
        foreach (scandir($from) ?: [] as $name) { if ($name !== '.' && $name !== '..') { $copy($from . '/' . $name, $to . '/' . $name); } }
    };
    try {
        foreach (['App', 'assets'] as $directory) { $copy($source . '/' . $directory, $fixture . '/' . $directory); }
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($fixture), $code);
    } finally {
        $base = realpath($fixture); $parent = realpath(\TinyCat\Tools\Workspace::directory('tests/tmp'));
        if ($base !== false && $parent !== false && dirname($base) === $parent && str_starts_with(basename($base), 'module-availability-')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isFile() || $entry->isLink()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
            }
            rmdir($base);
        }
    }
    exit($code);
}

define('TINYCAT', true);
$root = $argv[2];
require $root . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Modules\{Administration, Deployment, Distribution, Lifecycle, Loader, PackageRegistry, SourceModules};
use TinyCat\Database\Connection;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
$inventory = SourceModules::selection($root);
$plan = Distribution::describe($root, 'tinycat.social', $inventory, []);
file_put_contents($root . '/App/distribution.json', json_encode($plan, JSON_THROW_ON_ERROR));
mkdir($root . '/storage', 0700, true);
$pdo = new PDO('sqlite:' . $root . '/storage/test.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Connection::set($pdo);
Lifecycle::install($plan);
$before = Deployment::read($root);
rename($root . '/App/Modules/Blog', $root . '/removed-blog');
$snapshot = Administration::snapshot();
$assert(!in_array('tinycat.blog', array_column($snapshot['items'], 'id'), true), 'A removed never-active module disappears from the manager');
$assert(Deployment::read($root) === $before, 'Discovery does not alter installed state');
$assert(!Loader::load($root)->catalog->has('tinycat.blog'), 'Core boots when an inactive distributed module is absent');

$directory = $root . '/App/Modules/LocalNotes';
mkdir($directory . '/src', 0700, true);
mkdir($directory . '/migrations', 0700);
file_put_contents($directory . '/migrations/1.php', <<<'PHP'
<?php return static function (PDO $pdo): void { $pdo->exec('CREATE TABLE local_notes (id INTEGER PRIMARY KEY, body TEXT)'); };
PHP);
file_put_contents($directory . '/module.json', json_encode(['format' => 1, 'coreApi' => 1, 'id' => 'fixture.localnotes', 'namespace' => 'Fixture\\LocalNotes\\', 'providers' => ['routes' => 'Fixture\\LocalNotes\\Module', 'schema' => 'Fixture\\LocalNotes\\Module'], 'resources' => ['migrations' => 'migrations']], JSON_THROW_ON_ERROR));
file_put_contents($directory . '/src/Module.php', <<<'PHP'
<?php
namespace Fixture\LocalNotes;
file_put_contents(__DIR__ . '/loaded', 'yes');
final class Module implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesSchema
{
    public static function register(\TinyCat\Http\Api $api): void {}
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        $file = dirname(__DIR__) . '/migrations/1.php';
        return new \TinyCat\Core\Modules\ModuleSchema(1, ['local_notes'], require $file, [new \TinyCat\Core\Modules\SchemaMigration(1, 'fixture.localnotes.initial', $file)]);
    }
}
PHP);

$dependent = $root . '/App/Modules/LocalDependent';
mkdir($dependent, 0700);
file_put_contents($dependent . '/module.json', json_encode(['format' => 1, 'coreApi' => 1, 'id' => 'fixture.dependent', 'namespace' => 'Fixture\\Dependent\\', 'requires' => ['fixture.localnotes']], JSON_THROW_ON_ERROR));
$snapshot = Administration::snapshot();
$assert(in_array('fixture.localnotes', array_column($snapshot['items'], 'id'), true), 'A copied module appears without editing a central list');
$assert(!is_file($directory . '/src/loaded') && !is_file($root . '/' . PackageRegistry::FILE), 'Discovery loads no module PHP and writes no package registry');
$assert(!Loader::load($root)->catalog->has('fixture.localnotes'), 'A copied module starts inactive');
$result = Administration::change('fixture.dependent', true, $snapshot['revision']);
$assert(in_array('fixture.localnotes', $result['active'], true) && in_array('fixture.dependent', $result['active'], true) && Loader::load($root)->catalog->has('fixture.localnotes'), 'Explicit activation registers and enables a copied module');
$assert(isset(PackageRegistry::read($root)['fixture.localnotes']), 'Activation retains local ownership for updates and rollback');
$pdo->exec("INSERT INTO local_notes VALUES (1, 'Keep this record')");
rename($directory, $root . '/removed-notes');
$snapshot = Administration::snapshot();
$item = array_values(array_filter($snapshot['items'], static fn (array $item): bool => $item['id'] === 'fixture.localnotes'))[0];
$assert($item['active'] && !$item['available'], 'Missing active sources remain visible as a problem');
$assert(!Loader::load($root)->catalog->has('fixture.localnotes'), 'Missing active code is suspended without breaking core');
$assert(!Loader::load($root)->catalog->has('fixture.dependent'), 'Dependent modules are suspended when their dependency is missing');
try { Administration::change('fixture.localnotes', false, $snapshot['revision']); $assert(false, 'A required module cannot be disabled alone'); }
catch (DomainException $error) { $assert($error->getCode() === 422, 'Activation dependency rules remain enforced'); }
Administration::change('fixture.dependent', false, $snapshot['revision']);
Administration::change('fixture.localnotes', false, Administration::snapshot()['revision']);
$assert(Deployment::read($root)['active'] === [] && in_array('fixture.localnotes', Deployment::read($root)['installed'], true), 'Missing module can be disabled without removing its installed record');

$assert($pdo->query('SELECT body FROM local_notes')->fetchColumn() === 'Keep this record', 'Removing source files and disabling never deletes domain data');
rename($root . '/removed-blog', $root . '/App/Modules/Blog');
rename($dependent, $root . '/removed-dependent');
$assert(SourceModules::production($root) === ['tinycat.social' => 'App/Modules/Social/module.json'], 'Production inventory excludes the development Blog');
rename($root . '/removed-notes', $directory);
$assert(!Loader::load($root)->catalog->has('fixture.localnotes'), 'Restoring an explicitly disabled module does not activate it');
mkdir($root . '/App/Modules/Broken', 0700, true);
file_put_contents($root . '/App/Modules/Broken/module.json', '{invalid');
$assert(count(Administration::snapshot()['problems']) === 1, 'Malformed inactive metadata is reported without breaking the manager');
echo "PASS file-based module availability, inactive PHP isolation and local activation ({$checks} checks)\n";
