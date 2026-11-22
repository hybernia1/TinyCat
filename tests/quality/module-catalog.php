<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/support/workspace.php';

use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Catalog;
use TinyCat\Core\Modules\Definition;
use TinyCat\ModuleFixture\Calls;

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/Core/Runtime/autoload.php';
$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) { $failures[] = $message; }
};
$reject = static function (callable $action, string $message) use ($assert): void {
    try { $action(); $assert(false, $message . ' was accepted'); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() !== '', $message); }
};

// This must run before loading any fixture or the application bootstrap.
$legacy = Loader::load($root);
$seenFunctional = false; $frameworkFirst = true;
foreach ($legacy->catalog->definitions() as $definition) {
    if ($definition->manifest !== null) { $seenFunctional = true; }
    elseif ($seenFunctional) { $frameworkFirst = false; }
}
$assert($frameworkFirst, 'Mandatory framework initializes before functional modules without redundant activation dependencies');
$selection = require $root . '/App/modules.php';
$assert(count($legacy->catalog->definitions()) === count($selection) + count(TinyCat\Core\Runtime\Framework::CAPABILITIES), 'Every functional module and mandatory framework capability has offline metadata');
foreach (['TinyCat\\Support\\Config', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Database\\Connection', 'TinyCat\\Http\\Router', ...array_values($selection)] as $class) {
    $assert(!class_exists($class, false), 'Offline selection did not load ' . $class);
}
$assert($legacy->catalog->get('tinycat.social')->namespace === 'TinyCat\\Social\\', 'Legacy IDs identify the current namespace');
$assert((new Catalog([]))->definitions() === [], 'The technical catalog does not require a business module');
$reject(static fn () => Loader::fromSelection($root, ['tinycat.bots' => TinyCat\Social\Bots\Registration::class]), 'Bots without Publishing and Status');
$reject(static fn () => Loader::fromSelection($root, ['tinycat.notifications' => TinyCat\Core\Notifications\ServiceProvider::class]), 'Notifications without account identity');
$inbox = Loader::fromSelection($root, []);
$assert(!$inbox->catalog->has('tinycat.social') && $inbox->catalog->has('tinycat.accounts') && !$inbox->catalog->has('tinycat.publishing'), 'Framework-only selection has reusable publishing and identity without Social');

$temporary = \TinyCat\Tools\Workspace::directory('tests/tmp') . '/module-catalog-' . bin2hex(random_bytes(6));
mkdir($temporary, 0775, true);
$remove = static function (string $path) use (&$remove, $temporary): void {
    $resolved = realpath($path);
    $base = realpath($temporary);
    if ($resolved === false || $base === false || ($resolved !== $base && !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Refusing to remove a path outside the test fixture.');
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $child = $path . '/' . $entry;
        if (is_link($child)) { unlink($child); }
        elseif (is_dir($child)) { $remove($child); }
        else { unlink($child); }
    }
    rmdir($path);
};
$metadata = static fn (string $id, array $requires = []): stdClass => (object) [
    'format' => 1, 'coreApi' => 1, 'id' => 'fixture.' . $id,
    'namespace' => 'Fixture\\' . ucfirst($id) . '\\', 'requires' => $requires,
];
$definition = static fn (string $id, array $requires = []): Definition => Definition::fromMetadata($metadata($id, $requires), $temporary);

try {
    $sourceRoot = $temporary . '/source';
    mkdir($sourceRoot . '/App/Modules/Notes', 0775, true);
    mkdir($sourceRoot . '/App/Modules/Extra', 0775, true);
    mkdir($sourceRoot . '/App/Modules/NoManifest', 0775, true);
    $notes = ['format' => 1, 'coreApi' => 1, 'id' => 'fixture.notes', 'namespace' => 'Fixture\\Notes\\', 'requires' => ['fixture.extra']];
    $extra = ['format' => 1, 'coreApi' => 1, 'id' => 'fixture.extra', 'namespace' => 'Fixture\\Extra\\'];
    file_put_contents($sourceRoot . '/App/Modules/Notes/module.json', json_encode($notes, JSON_THROW_ON_ERROR));
    file_put_contents($sourceRoot . '/App/Modules/Extra/module.json', json_encode($extra, JSON_THROW_ON_ERROR));
    file_put_contents($sourceRoot . '/App/Modules/Notes/Module.php', '<?php throw new RuntimeException("Module PHP must not execute");');
    $sources = TinyCat\Core\Modules\SourceModules::selection($sourceRoot);
    $assert(array_keys($sources) === ['fixture.extra', 'fixture.notes'], 'Source inventory reads new module IDs from JSON without a central list or PHP execution');
    $assert(TinyCat\Core\Modules\SourceModules::selection($sourceRoot, 'fixture.notes') === $sources, 'Standalone source selection includes transitive dependencies');
    $assert(array_keys(TinyCat\Core\Modules\SourceModules::selection($sourceRoot, 'fixture.extra')) === ['fixture.extra'], 'Standalone source selection excludes unrelated modules');
    $reject(static fn () => TinyCat\Core\Modules\SourceModules::selection($sourceRoot, 'fixture.missing'), 'Unknown source module');
    $notes['requires'] = ['fixture.missing'];
    file_put_contents($sourceRoot . '/App/Modules/Notes/module.json', json_encode($notes, JSON_THROW_ON_ERROR));
    $reject(static fn () => TinyCat\Core\Modules\SourceModules::selection($sourceRoot, 'fixture.notes'), 'Missing source dependency');
    $extra['id'] = 'fixture.notes';
    file_put_contents($sourceRoot . '/App/Modules/Extra/module.json', json_encode($extra, JSON_THROW_ON_ERROR));
    $reject(static fn () => TinyCat\Core\Modules\SourceModules::selection($sourceRoot), 'Duplicate discovered module ID');
    $alpha = $definition('alpha');
    $beta = $definition('beta', ['fixture.alpha']);
    $gamma = $definition('gamma');
    $ids = static fn (Catalog $catalog): array => array_map(static fn (Definition $item): string => $item->id, $catalog->definitions());
    $assert($ids(new Catalog([$gamma, $beta, $alpha])) === ['fixture.alpha', 'fixture.beta', 'fixture.gamma'], 'Dependencies precede dependants with stable ID tie breaking');
    $assert($ids(new Catalog([$alpha, $gamma, $beta])) === $ids(new Catalog([$gamma, $beta, $alpha])), 'Input order does not change the resolved catalog');
    $reject(static fn () => new Catalog([$alpha, $alpha]), 'Duplicate module ID');
    $reject(static fn () => new Catalog([$beta]), 'Missing dependency');
    $reject(static fn () => new Catalog([$definition('alpha', ['fixture.beta']), $beta]), 'Two-module dependency cycle');
    $reject(static fn () => new Catalog([$definition('alpha', ['fixture.beta']), $definition('beta', ['fixture.gamma']), $definition('gamma', ['fixture.alpha'])]), 'Three-module dependency cycle');
    $reject(static fn () => $definition('alpha', ['fixture.alpha']), 'Self dependency');
    $reject(static fn () => $definition('alpha', ['fixture.beta', 'fixture.beta']), 'Duplicate dependency');
    $reject(static fn () => $definition('alpha', ['INVALID']), 'Invalid dependency ID');
    $reject(static fn () => (new Catalog([$alpha]))->get('fixture.other'), 'Unselected module lookup');
    foreach ([
        'format' => 2, 'coreApi' => '1', 'id' => 'Alpha', 'namespace' => 'Fixture',
        'requires' => (object) [], 'exports' => ['Foreign\\Contract'],
        'providers' => ['routes' => 'Fixture\\Alpha\\Routes'],
        'resources' => (object) ['unknown' => 'x'], 'unknown' => true,
    ] as $key => $value) {
        $data = $metadata('alpha');
        $data->$key = $value;
        $reject(static fn () => Definition::fromMetadata($data, $temporary), 'Malformed metadata field ' . $key);
    }
    foreach ([(object) ['unknown' => 'Fixture\\Alpha\\Provider'], (object) ['routes' => 'Foreign\\Routes'], (object) ['routes' => 123]] as $providers) {
        $data = $metadata('alpha');
        $data->providers = $providers;
        $reject(static fn () => Definition::fromMetadata($data, $temporary), 'Invalid provider declaration');
    }
    foreach (['settings', 'permissions', 'tasks', 'navigation', 'health'] as $kind) {
        $data = $metadata('alpha'); $data->providers = (object) [$kind => 'Fixture\\Alpha\\Provider'];
        $reject(static fn () => Definition::fromMetadata($data, $temporary), 'Unsupported provider rejected during metadata parsing: ' . $kind);
    }
    foreach (['requires', 'exports', 'providers', 'resources'] as $field) {
        $bad = $metadata('alpha');
        $bad->$field = null;
        $reject(static fn () => Definition::fromMetadata($bad, $temporary), 'Null optional metadata ' . $field);
    }
    $overlap = $metadata('other');
    $overlap->namespace = 'fixture\\Alpha\\Internal\\';
    $reject(static fn () => new Catalog([$alpha, Definition::fromMetadata($overlap, $temporary)]), 'Overlapping namespace ownership, including case');

    mkdir($temporary . '/lang');
    mkdir($temporary . '/assets');
    file_put_contents($temporary . '/assets/module.json', '[]');
    $data = $metadata('alpha');
    $data->providers = (object) ['routes' => 'Fixture\\Alpha\\Routes'];
    $data->resources = (object) ['translations' => 'lang', 'assets' => 'assets/module.json'];
    file_put_contents($temporary . '/module.json', json_encode($data, JSON_THROW_ON_ERROR));
    $catalog = Catalog::fromManifests(['fixture.alpha' => $temporary . '/module.json']);
    $assert($catalog->get('fixture.alpha')->resources['translations'] === realpath($temporary . '/lang'), 'Resource paths are canonical and module owned');
    $assert(!class_exists('Fixture\\Alpha\\Routes', false), 'Loading providers only records class names');
    // An unselected malformed manifest must never be inspected or activated.
    mkdir($temporary . '/unselected');
    file_put_contents($temporary . '/unselected/module.json', '{broken');
    $assert(count(Catalog::fromManifests(['fixture.alpha' => $temporary . '/module.json'])->definitions()) === 1, 'Only explicitly selected manifests are loaded');
    $reject(static fn () => Catalog::fromManifests(['fixture.other' => $temporary . '/module.json']), 'Selection/manifest identity mismatch');
    foreach (['../outside', '/absolute', 'C:/absolute', 'assets/../lang', 'assets//module.json', 'missing', "lang\0suffix", 'assets'] as $path) {
        $bad = clone $data;
        $bad->resources = (object) ['assets' => $path];
        $reject(static fn () => Definition::fromMetadata($bad, $temporary), 'Invalid resource ' . json_encode($path));
    }
    $bad = clone $data;
    $bad->resources = (object) ['translations' => 'assets/module.json'];
    $reject(static fn () => Definition::fromMetadata($bad, $temporary), 'File used as resource directory');
    $reject(static fn () => Definition::fromManifest($temporary . '/missing.json'), 'Missing manifest');
    foreach (['{broken', '[]', 'null', '1'] as $source) {
        file_put_contents($temporary . '/invalid.json', $source);
        $reject(static fn () => Definition::fromManifest($temporary . '/invalid.json'), 'Malformed manifest JSON');
    }
    mkdir($temporary . '/outside');
    file_put_contents($temporary . '/outside/module.json', '[]');
    if (@symlink($temporary . '/outside/module.json', $temporary . '/assets/escape.json')) {
        // Narrow the module root so the link's target is genuinely outside it.
        $bad = $metadata('escape');
        $bad->resources = (object) ['assets' => 'escape.json'];
        $reject(static fn () => Definition::fromMetadata($bad, $temporary . '/assets'), 'Symlink escaping module root');
    } else { echo "SKIP symlink escape fixture: symlink creation unavailable\n"; }

    require $root . '/tests/fixtures/modules/legacy.php';
    foreach (['Alpha' => ['alpha', 'fixture_alpha'], 'Beta' => ['beta', 'fixture_beta'], 'Duplicate' => ['invalid', 'fixture_alpha'], 'Invalid' => ['invalid', 'invalid-table']] as $owner => [$id, $table]) {
        $directory = $temporary . '/App/Modules/Fixture' . $owner; mkdir($directory . '/src', 0700, true);
        $namespace = 'TinyCat\\Fixture' . $owner;
        $source = '<?php namespace ' . $namespace . '; final class Module implements \\TinyCat\\Core\\Modules\\StorageModule { public static function tables(): array { return [' . var_export($table, true) . ']; } public static function register(\\TinyCat\\Http\\Api $api): void { \\TinyCat\\ModuleFixture\\Calls::$registered[] = ' . var_export(strtolower($owner), true) . '; } }';
        file_put_contents($directory . '/src/Module.php', $source);
        file_put_contents($directory . '/module.json', json_encode(['format' => 1, 'coreApi' => 1, 'id' => 'fixture.' . $id, 'namespace' => $namespace . '\\', 'providers' => ['routes' => $namespace . '\\Module']], JSON_THROW_ON_ERROR));
    }
    $adapter = Loader::fromSelection($temporary, [
        'fixture.beta' => TinyCat\FixtureBeta\Module::class,
        'fixture.alpha' => TinyCat\FixtureAlpha\Module::class,
    ]);
    $tables = $adapter->register(new TinyCat\Http\Api());
    $assert(Calls::$registered === ['alpha', 'beta'], 'Module routes follow dependency order');
    $assert($tables === ['fixture_alpha', 'fixture_beta'], 'Module table inventory follows dependency order');
    foreach ([TinyCat\FixtureDuplicate\Module::class, TinyCat\FixtureInvalid\Module::class] as $invalid) {
        Calls::$registered = [];
        $adapter = Loader::fromSelection($temporary, ['fixture.alpha' => TinyCat\FixtureAlpha\Module::class, 'fixture.invalid' => $invalid]);
        $reject(static fn () => $adapter->register(new TinyCat\Http\Api()), 'Invalid/duplicate table ownership');
        $assert(Calls::$registered === [], 'Invalid table inventory fails before any routes are registered');
    }
    $reject(static fn () => Loader::fromSelection($temporary, [TinyCat\FixtureAlpha\Module::class]), 'Legacy selection without stable ID');
    $reject(static fn () => Loader::fromSelection($temporary, ['fixture.alpha' => 123]), 'Invalid legacy class binding');
    $reject(static fn () => Loader::fromSelection($temporary, ['fixture.alpha' => 'Foreign\\Module']), 'Foreign legacy class binding');
    // End-to-end inventory against all current built-ins, still without executing a handler.
    $expected = [];
    foreach ($legacy->providers(TinyCat\Core\Modules\StorageModule::class) as $class) { $expected = [...$expected, ...$class::tables()]; }
    foreach ($legacy->schemas() as $schema) { $expected = [...$expected, ...$schema->tables]; }
    $expected = [...array_values(array_unique($expected)), 'migrations', 'module_installations'];
    $expected = array_values(array_unique($expected));
    $assert($legacy->register(new TinyCat\Http\Api()) === $expected, 'All current table declarations are preserved');

    $lazyRoot = $temporary . '/lazy-selection';
    mkdir($lazyRoot . '/App', 0700, true);
    $emptyPlan = ['format' => 3, 'framework' => TinyCat\Core\Runtime\Framework::CAPABILITIES, 'schemaOwners' => [], 'product' => 'fixture.runtime', 'selection' => [], 'active' => [], 'required' => [], 'requires' => [], 'schemas' => [], 'legacy' => false];
    file_put_contents($lazyRoot . '/App/distribution.json', json_encode($emptyPlan, JSON_THROW_ON_ERROR));
    file_put_contents($lazyRoot . '/App/modules.php', '<?php throw new RuntimeException("Source selection must remain lazy.");');
    $assert(Loader::load($lazyRoot)->catalog->has('tinycat.ui'), 'A distribution loads without evaluating source module selection');
    unlink($lazyRoot . '/App/distribution.json');
    file_put_contents($lazyRoot . '/App/modules.php', '<?php return "invalid";');
    $reject(static fn () => Loader::load($lazyRoot), 'Non-array source selection');
    $sourceCalls = 0;
    $sourceSelection = TinyCat\Core\Modules\Deployment::selection($lazyRoot, static function () use (&$sourceCalls): array { $sourceCalls++; return []; });
    $assert($sourceSelection === [] && $sourceCalls === 1, 'Source selection is evaluated once when no distribution exists');

    // Current activation IDs preserve installed owners without historical remapping.
    $plan = TinyCat\Core\Modules\Distribution::describe($root, 'tinycat.social', \TinyCat\Core\Modules\SourceModules::selection($root));
    $assert($plan['format'] === 4 && $plan['required'] === [] && array_keys($plan['selection']) === ['tinycat.social'], 'Signed selection archives history and contains only optional functional modules');
    $standalone = TinyCat\Core\Modules\Distribution::describe($root, 'tinycat.social', \TinyCat\Core\Modules\SourceModules::selection($root));
    foreach (['tinycat.users', 'tinycat.moderation', 'tinycat.bots'] as $stream) {
        $assert($standalone['schemaOwners'][$stream] === 'tinycat.social', 'Social owns the unchanged historical stream: ' . $stream);
    }
    $assert($standalone['schemaOwners']['tinycat.status'] === 'tinycat.social', 'Publishing owns shared interactions under the unchanged historical stream');
    foreach ($plan['schemas'] as $stream => $schema) {
        $assert(isset($schema['fresh']) && is_file($root . '/' . $schema['fresh']['file']) && !isset($schema['legacy']), 'Each archived built-in owner has an independent fresh baseline: ' . $stream);
        foreach ($schema['migrations'] as $migration) {
            if ($migration['id'] === 'tinycat.accounts.avatar-version') {
                $assert($migration['file'] === 'App/Core/Identity/migrations/avatar-version.php' && $migration['version'] === 2 && hash_equals($migration['checksum'], \TinyCat\Database\ModuleSchemas::checksum($root . '/' . $migration['file'])), 'The new avatar migration remains executable and authenticated after the archived prefix');
                continue;
            }
            $assert($migration['file'] === null && preg_match('/^[a-f0-9]{64}$/D', $migration['checksum']) === 1, 'Built-in history keeps its immutable ID, sequence and checksum without executable PHP: ' . $migration['id']);
        }
    }
    $transitionRoot = $temporary . '/transition';
    mkdir($transitionRoot . '/App', 0700, true); mkdir($transitionRoot . '/storage', 0700);
    $oldPlan = $plan; $oldPlan['active'] = [];
    file_put_contents($transitionRoot . '/App/distribution.json', json_encode($oldPlan, JSON_THROW_ON_ERROR));
    TinyCat\Core\Modules\Deployment::write($transitionRoot, $oldPlan, $oldPlan['active'], array_keys($oldPlan['selection']), '6.0.6');
    $before = file_get_contents($transitionRoot . '/storage/deployment.json');
    $transition = TinyCat\Core\Modules\Deployment::transition($transitionRoot, $plan);
    $assert($transition === ['active' => [], 'installed' => ['tinycat.social']], 'A completed current deployment retains disabled installed data owners without enabling them');
    $assert(file_get_contents($transitionRoot . '/storage/deployment.json') === $before, 'Upgrade discovery does not mutate deployment state');
    $withoutSocial = $plan; unset($withoutSocial['selection']['tinycat.social'], $withoutSocial['requires']['tinycat.social']); $withoutSocial['active'] = [];
    try { TinyCat\Core\Modules\Deployment::transition($transitionRoot, $withoutSocial); $assert(false, 'Removing installed Social must fail'); }
    catch (RuntimeException) { $assert(true, 'A new artifact cannot discard retained Social sources or data'); }
    foreach ([1, 2] as $format) {
        $invalid = $plan; $invalid['format'] = $format;
        try { TinyCat\Core\Modules\Distribution::validate($invalid); $assert(false, 'Historical distribution format was accepted'); }
        catch (RuntimeException) { $assert(true, 'Historical distribution requires the unchanged 6.0.6 bridge'); }
    }
    $invalid = $plan; $invalid['selection']['fixture.extra'] = 'App/Modules/Extra/module.json'; $invalid['requires']['fixture.extra'] = [];
    $assert(isset(TinyCat\Core\Modules\Distribution::validate($invalid)['selection']['fixture.extra']), 'Additional functional owners use the same registration contract without a core allowlist');
    $invalid = $plan; $invalid['selection']['tinycat.settings'] = 'App/Modules/Extra/module.json'; $invalid['requires']['tinycat.settings'] = [];
    try { TinyCat\Core\Modules\Distribution::validate($invalid); $assert(false, 'Framework capability was accepted as a functional module'); }
    catch (RuntimeException) { $assert(true, 'Mandatory framework ownership cannot be overridden by a functional module'); }
    $invalidState = json_decode($before, true, 512, JSON_THROW_ON_ERROR); $invalidState['installed'][] = 'fixture.removed';
    file_put_contents($transitionRoot . '/storage/deployment.json', json_encode($invalidState, JSON_THROW_ON_ERROR));
    try { TinyCat\Core\Modules\Deployment::transition($transitionRoot, $plan); $assert(false, 'An unknown installed owner was silently discarded'); }
    catch (RuntimeException) { $assert(true, 'Unrecognized installed IDs are rejected without silently rewriting ownership'); }
    file_put_contents($transitionRoot . '/storage/deployment.json', $before);
    $invalid = $plan; $invalid['framework'] = [];
    try { TinyCat\Core\Modules\Distribution::validate($invalid); $assert(false, 'Incomplete core must fail'); }
    catch (RuntimeException) { $assert(true, 'A signed plan cannot deactivate mandatory framework capabilities'); }
} finally { $remove($temporary); }

if ($failures !== []) {
    foreach ($failures as $failure) { fwrite(STDERR, 'FAIL ' . $failure . "\n"); }
    exit(1);
}
echo 'PASS offline module catalog, manifest validation and legacy composition (' . $checks . " checks)\n";
