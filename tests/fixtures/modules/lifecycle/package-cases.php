<?php
declare(strict_types=1);

// Extends the existing signed lifecycle fixture; every installation and DB is disposable.
if ($packagePhase === 'install') {
    $makeModule('addon', 1, ['tinycat.accounts']);
    $moduleZip = $workspace . '/module-v1/fixture.addon-1.0.0.zip';
    [$exit, $text, $error] = $run([PHP_BINARY, $candidate . '/tools/release/build-module.php', '--module=App/Modules/Addon', '--version=1.0.0', '--minimum-core=6.0.0-dev.10', '--output=' . $workspace . '/module-v1'], $candidate);
    $assert($exit === 0 && is_file($moduleZip), 'Author builds a standalone module artifact: ' . $text . $error);
    [$exit, $text, $error] = $console(['module-install', $moduleZip]);
    $assert($exit === 0, 'Existing installation accepts an independent module ZIP: ' . $text . $error);
    $registryBytes = file_get_contents($root . '/App/module-packages.json');
    $assert(!$pdo->query("SELECT name FROM sqlite_master WHERE name = 'lifecycle_addon'")->fetchColumn(), 'Uploading an inactive module does not run its schema');
    $status = json_decode($console(['status'])[1], true);
    $assert(in_array('fixture.addon', $status['available'], true) && !in_array('fixture.addon', $status['deployment']['active'], true), 'Uploaded module is available but not activated');
    [$exit, $text, $error] = $console(['activate', 'fixture.addon']);
    $assert($exit === 0 && $pdo->query("SELECT name FROM sqlite_master WHERE name = 'lifecycle_addon'")->fetchColumn() === 'lifecycle_addon', 'First activation migrates the module through the common lifecycle: ' . $text . $error);
    $pdo->exec("INSERT INTO lifecycle_addon(body) VALUES ('Retained addon data')");
    $addonHash = hash_file('sha256', $root . '/App/Modules/Addon/src/Module.php');
    // An inventory cannot authorize paths outside the module, extra ZIP files or changed bytes.
    foreach (['traversal', 'tampered', 'namespace', 'missing-dependency', 'core-table'] as $attack) {
        $bad = $workspace . '/bad-' . $attack . '.zip'; copy($moduleZip, $bad);
        $zip = new ZipArchive(); $zip->open($bad); $meta = json_decode($zip->getFromName('package.json'), true);
        if ($attack === 'traversal') { $zip->addFromString('../config.php', '<?php'); }
        if ($attack === 'tampered') { $zip->addFromString('src/Module.php', '<?php echo "tampered";'); }
        if (in_array($attack, ['namespace', 'missing-dependency'], true)) {
            if ($attack === 'namespace') { $meta['manifest']['namespace'] = 'TinyCat\\Core\\'; }
            else { $meta['manifest']['requires'] = ['unavailable.dependency']; }
            $body = json_encode($meta['manifest']); $meta['files']['module.json'] = hash('sha256', $body); $zip->addFromString('module.json', $body);
        }
        if ($attack === 'core-table') { $meta['schemas']['fixture.addon']['tables'] = ['users']; }
        $meta['version'] = '9.0.0'; $zip->addFromString('package.json', json_encode($meta)); $zip->close();
        [$exit] = $console(['module-install', $bad]);
        $assert($exit !== 0 && file_get_contents($root . '/App/module-packages.json') === $registryBytes && hash_file('sha256', $root . '/App/Modules/Addon/src/Module.php') === $addonHash && !is_file($root . '/storage/maintenance.json'), 'Invalid module is rejected before changing files or state: ' . $attack);
    }
    // A clean core-only deployment can later receive exactly the same package.
    $coreHost = $workspace . '/core-only'; mkdir($coreHost, 0700);
    foreach (['App/Core', 'assets', 'lang'] as $path) { $copy($root . '/' . $path, $coreHost . '/' . $path); }
    foreach (['App/autoload.php', 'App/Console.php'] as $path) { $copy($root . '/' . $path, $coreHost . '/' . $path); }
    file_put_contents($coreHost . '/App/modules.php', '<?php return [];');
    file_put_contents($coreHost . '/config.php', '<?php return ' . var_export(['database' => ['dsn' => 'sqlite:' . $coreHost . '/core.sqlite'], 'i18n' => ['locale' => 'en']], true) . ';');
    $prepare = 'define("TINYCAT", true); require "App/autoload.php"; $p=TinyCat\\Core\\Modules\\Distribution::describe(getcwd(), "fixture.core", []); file_put_contents("App/distribution.json", json_encode($p));';
    [$exit, $text, $error] = $run([PHP_BINARY, '-r', $prepare], $coreHost); $assert($exit === 0, 'Create core-only descriptor: ' . $text . $error);
    [$exit, $text, $error] = $run([PHP_BINARY, $coreHost . '/App/Console.php', 'install'], $coreHost, $administrator); $assert($exit === 0, 'Core-only CLI install creates its administrator: ' . $text . $error);
    $coreDb = new PDO('sqlite:' . $coreHost . '/core.sqlite');
    $assert(count($coreDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll()) === 8, 'Core-only installation has exactly eight framework tables');
    [$exit, $text, $error] = $run([PHP_BINARY, $coreHost . '/App/Console.php', 'module-install', $moduleZip], $coreHost); $assert($exit === 0, 'Core-only installation receives its first module ZIP: ' . $text . $error);
    [$exit, $text, $error] = $run([PHP_BINARY, $coreHost . '/App/Console.php', 'activate', 'fixture.addon'], $coreHost); $assert($exit === 0, 'First module activates without Social or Blog: ' . $text . $error);
    $assert(count($coreDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll()) === 9, 'First activation adds only the module-owned table');
    $coreDb = null;
}

if ($packagePhase === 'after-core-update') {
    $assert(file_get_contents($root . '/App/module-packages.json') === $registryBytes && hash_file('sha256', $root . '/App/Modules/Addon/src/Module.php') === $addonHash && $pdo->query('SELECT body FROM lifecycle_addon')->fetchColumn() === 'Retained addon data', 'Core update preserves independent module code, catalog and data');
    $makeModule('addon', 2, ['tinycat.accounts']);
    $moduleV2 = $workspace . '/module-v2/fixture.addon-2.0.0.zip';
    [$exit, $text, $error] = $run([PHP_BINARY, $candidate . '/tools/release/build-module.php', '--module=App/Modules/Addon', '--version=2.0.0', '--minimum-core=6.0.0-dev.10', '--output=' . $workspace . '/module-v2'], $candidate); $assert($exit === 0, 'Build module upgrade: ' . $text . $error);
    [$exit, $text, $error] = $run([PHP_BINARY, $fixture . '/module-update-worker.php', $root, $moduleV2], $root);
    $assert($exit === 0 && (json_decode($text, true)['cached_version'] ?? null) === 1 && (int) $pdo->query('SELECT revision FROM lifecycle_addon')->fetchColumn() === 2, 'Module upgrade uses immutable migration files despite a cached old provider: ' . $text . $error);
    $operation = json_decode(file_get_contents($root . '/storage/updates/operation.json'), true);
    [$exit, $text, $error] = $console(['rollback', $operation['backup']]);
    $assert($exit === 0 && file_get_contents($root . '/App/module-packages.json') === $registryBytes && hash_file('sha256', $root . '/App/Modules/Addon/src/Module.php') === $addonHash, 'Shared rollback restores exact module files and catalog bytes: ' . $text . $error);
    $assert((int) $pdo->query("SELECT version FROM module_installations WHERE module_id='fixture.addon'")->fetchColumn() === 1 && $pdo->query('SELECT body FROM lifecycle_addon')->fetchColumn() === 'Retained addon data', 'Module rollback restores DB schema, history and retained data');
    $makeModule('addon', 4, ['tinycat.accounts']);
    [$exit, $text, $error] = $run([PHP_BINARY, $candidate . '/tools/release/build-module.php', '--module=App/Modules/Addon', '--version=4.0.0', '--minimum-core=6.0.0-dev.10', '--output=' . $workspace . '/module-v4'], $candidate); $assert($exit === 0, 'Build a module with a reproducible failing migration: ' . $text . $error);
    [$exit] = $console(['module-install', $workspace . '/module-v4/fixture.addon-4.0.0.zip']);
    $assert($exit !== 0 && is_file($root . '/storage/maintenance.json'), 'A failed module migration retains the common maintenance and recovery journal');
    $operation = json_decode(file_get_contents($root . '/storage/updates/operation.json'), true);
    [$exit, $text, $error] = $console(['rollback', $operation['backup']]);
    $assert($exit === 0 && file_get_contents($root . '/App/module-packages.json') === $registryBytes && $pdo->query('SELECT body FROM lifecycle_addon')->fetchColumn() === 'Retained addon data' && !$pdo->query("SELECT name FROM sqlite_master WHERE name='failed_migration'")->fetchColumn(), 'Rollback of a failed module update restores files, registry and DB: ' . $text . $error);
}
