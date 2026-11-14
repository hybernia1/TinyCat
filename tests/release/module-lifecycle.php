<?php
declare(strict_types=1);

// Real signed products and fresh PHP workers; the developer's DB/config/storage are never targets.
$source = dirname(__DIR__, 2);
$workspace = sys_get_temp_dir() . '/tinycat-distribution-' . bin2hex(random_bytes(10));
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_file($from)) { if (!is_dir(dirname($to))) { mkdir(dirname($to), 0700, true); } copy($from, $to); return; }
    if (!is_dir($to)) { mkdir($to, 0700, true); }
    foreach (scandir($from) ?: [] as $name) { if ($name !== '.' && $name !== '..' && !is_link($from . '/' . $name)) { $copy($from . '/' . $name, $to . '/' . $name); } }
};
$run = static function (array $command, string $directory, string $input = '', array $environment = []): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, array_merge(getenv(), $environment));
    if (!is_resource($process)) { throw new RuntimeException('Cannot start distribution fixture worker.'); }
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), (string) $output, (string) $errors];
};
$http = null;
try {
    mkdir($workspace, 0700);
    $candidate = $workspace . '/source';
    foreach (['App', 'assets', 'lang', 'tools', 'migrations'] as $directory) { $copy($source . '/' . $directory, $candidate . '/' . $directory); }
    foreach (['index.php', 'scheduled-tasks.php', '.htaccess', 'README.md', 'LICENSE'] as $file) { $copy($source . '/' . $file, $candidate . '/' . $file); }
    foreach (['App/Core/Support/compiled', 'assets/build'] as $generated) {
        $path = $candidate . '/' . $generated;
        $resolvedGenerated = realpath($path); $resolvedWorkspace = realpath($workspace);
        if ($resolvedGenerated !== false && ($resolvedWorkspace === false || !str_starts_with($resolvedGenerated, $resolvedWorkspace . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR))) { throw new RuntimeException('Unsafe generated fixture path.'); }
        if (is_dir($path)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); } rmdir($path); }
    }
    $fixture = $source . '/tests/fixtures/modules/lifecycle';
    $makeModule = static function (string $short, int $version, array $requires) use ($candidate, $fixture): void {
        $id = 'fixture.' . $short; $namespace = 'Lifecycle' . ucfirst($short); $table = 'lifecycle_' . $short;
        $root = $candidate . '/App/Modules/' . ucfirst($short);
        foreach (['src', 'migrations', 'lang', 'assets'] as $directory) { if (!is_dir($root . '/' . $directory)) { mkdir($root . '/' . $directory, 0700, true); } }
        file_put_contents($root . '/src/Module.php', strtr((string) file_get_contents($fixture . '/Module.php.tpl'), ['@@NAMESPACE@@' => $namespace, '@@ID@@' => $id, '@@SHORT@@' => $short, '@@TABLE@@' => $table, '@@VERSION@@' => (string) $version]));
        $class = 'TinyCat\\Modules\\' . $namespace . '\\Module';
        file_put_contents($root . '/module.json', json_encode(['format' => 1, 'coreApi' => 1, 'id' => $id, 'namespace' => 'TinyCat\\Modules\\' . $namespace . '\\', 'requires' => $requires,
            'providers' => ['routes' => $class, 'schema' => $class, 'contributions' => $class], 'resources' => ['translations' => 'lang', 'assets' => 'assets/module.json', 'migrations' => 'migrations']], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/migrations/1.php', '<?php return static function (PDO $pdo): void { $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === \'mysql\' ? \'INTEGER PRIMARY KEY AUTO_INCREMENT\' : \'INTEGER PRIMARY KEY AUTOINCREMENT\'; $pdo->exec(\'CREATE TABLE ' . $table . ' (id \' . $id . \', body TEXT NOT NULL, ticks INTEGER NOT NULL DEFAULT 0)\'); };');
        if ($version >= 2) { file_put_contents($root . '/migrations/2.php', '<?php return static function (PDO $pdo): void { $pdo->exec(\'ALTER TABLE ' . $table . ' ADD COLUMN revision INTEGER NOT NULL DEFAULT 2\'); };'); }
        if ($version >= 3) { file_put_contents($root . '/migrations/3.php', '<?php return static function (PDO $pdo): void { $pdo->exec(\'ALTER TABLE ' . $table . ' ADD COLUMN revision_three INTEGER NOT NULL DEFAULT 3\'); };'); }
        if ($version >= 4) { file_put_contents($root . '/migrations/4.php', '<?php return static function (PDO $pdo): void { $pdo->exec(\'CREATE TABLE failed_migration (id INTEGER)\'); throw new RuntimeException(\'Injected migration failure\'); };'); }
        $relative = 'App/Modules/' . ucfirst($short) . '/assets/';
        file_put_contents($root . '/assets/module.json', json_encode([['routes' => ['public'], 'entries' => [$relative . 'index.js'], 'styles' => [$relative . 'style.css'], 'components' => [], 'translations' => [$table . '.title']]], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/assets/index.js', 'window.lifecycle' . ucfirst($short) . 'Loaded = true;');
        file_put_contents($root . '/assets/style.css', '.lifecycle-' . $short . ' { color: inherit; }');
        foreach (glob($candidate . '/lang/*.json') ?: [] as $language) { $locale = basename($language); file_put_contents($root . '/lang/' . $locale, json_encode([$table => ['title' => 'Selected ' . $short]], JSON_THROW_ON_ERROR)); }
    };
    $makeModule('notes', 1, ['tinycat.accounts']); $makeModule('reports', 1, ['fixture.notes']);
    $selection = require $candidate . '/App/Modules/Blog/selection.php'; $base = array_keys($selection);
    $selection += ['fixture.notes' => 'App/Modules/Notes/module.json', 'fixture.reports' => 'App/Modules/Reports/module.json'];
    file_put_contents($candidate . '/App/Modules/Blog/selection.php', '<?php return ' . var_export($selection, true) . ';');
    $pair = sodium_crypto_sign_keypair(); $secret = sodium_crypto_sign_secretkey($pair); $public = sodium_crypto_sign_publickey($pair);
    $key = $workspace . '/signing.key'; file_put_contents($key, base64_encode($secret));
    $build = static function (string $version, string $output) use ($run, $candidate, $key, $base, $assert): void {
        [$exit, $text, $error] = $run([PHP_BINARY, $candidate . '/tools/release/build-update.php', '--version=' . $version, '--assembly=blog', '--active=' . implode(',', $base), '--allow-dirty', '--both', '--output=' . $output, '--key=' . $key], $candidate);
        $assert($exit === 0, 'Signed Blog build: ' . $text . $error);
    };
    $initialVersionFile = $candidate . '/App/Core/Support/Version.php';
    file_put_contents($initialVersionFile, preg_replace("/CURRENT = '[^']+'/", "CURRENT = '6.0.0-dev.10'", (string) file_get_contents($initialVersionFile)));
    $build('6.0.0-dev.10', $workspace . '/v1');
    $installManifest = json_decode((string) file_get_contents($workspace . '/v1/install/tinycat-blog-install.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert($installManifest['distribution']['product'] === 'tinycat.blog' && $installManifest['minimum_version'] === '6.0.0-dev.9.1', 'Signed identity prevents old updaters from applying module packages');
    $assert($installManifest['migrations'] === [] && !isset($installManifest['files']['App/Modules/Social/src/Posts/Registration.php']) && isset($installManifest['files']['App/Core/Settings/Store.php']) && isset($installManifest['files']['App/Modules/Notes/src/Module.php']), 'Blog package contains only available product owners, including inactive modules');
    $assert(isset($installManifest['files']['scheduled-tasks.php']) && isset($installManifest['files']['App/Console.php']), 'Blog has a working production CLI instead of a missing social scheduler');
    $root = $workspace . '/installation'; mkdir($root, 0700);
    $zip = new ZipArchive(); $zip->open($workspace . '/v1/install/' . $installManifest['package']); $zip->extractTo($root); $zip->close();
    $config = ['database' => ['dsn' => 'sqlite:' . $root . '/database.sqlite'], 'updates' => ['public_key' => base64_encode($public)], 'i18n' => ['locale' => 'en']];
    file_put_contents($root . '/config.php', '<?php return ' . var_export($config, true) . ';'); $configHash = hash_file('sha256', $root . '/config.php');
    $entry = $root . '/App/Console.php';
    $console = static function (array $args, string $input = '') use ($run, $root, &$entry): array { return $run([PHP_BINARY, $entry, ...$args], $root, $input); };
    $administrator = json_encode(['username' => 'distribution_admin', 'password' => 'Module-Regression-42', 'password_confirm' => 'Module-Regression-42', 'locale' => 'en'], JSON_THROW_ON_ERROR);
    [$exit, $text, $error] = $console(['install'], $administrator); $assert($exit === 0, 'Fresh artifact CLI installation: ' . $text . $error);
    [$exit, $text, $error] = $console(['rescue']); $assert($exit === 0 && is_file($root . '/storage/updates/console.php'), 'Private rescue launcher is available without dev tools: ' . $text . $error);
    $entry = $root . '/storage/updates/console.php';
    $pdo = new PDO('sqlite:' . $root . '/database.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $assert(count($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll()) === 21, 'Fresh signed Blog creates exactly its declared tables');
    $assert(password_verify('Module-Regression-42', (string) $pdo->query('SELECT password FROM users')->fetchColumn()), 'CLI uses the shared account password writer');
    [$exit] = $console(['install'], $administrator); $assert($exit !== 0 && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Fresh installer refuses to adopt existing data');
    $packagePhase = 'install'; require $fixture . '/package-cases.php';
    [$exit] = $console(['deactivate', 'tinycat.accounts']); $assert($exit !== 0, 'Required product modules cannot be disabled');
    [$exit] = $console(['activate', 'fixture.unknown']); $assert($exit !== 0, 'An unknown capability blocks activation before DDL');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if ($socket === false) { throw new RuntimeException('Cannot allocate fixture HTTP port.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $http = proc_open([PHP_BINARY, '-S', $address, '-t', $root, $fixture . '/worker.php'], [0 => ['pipe', 'r'], 1 => ['file', $workspace . '/http.log', 'a'], 2 => ['file', $workspace . '/http.log', 'a']], $httpPipes, $root, array_merge(getenv(), ['TINYCAT_DISTRIBUTION_ROOT' => $root]));
    if (!is_resource($http)) { throw new RuntimeException('Cannot start product HTTP worker.'); }
    fclose($httpPipes[0]);
    $request = static function (string $path) use ($address): array {
        $curl = curl_init('http://' . $address . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        return [$status, is_string($body) ? $body : ''];
    };
    for ($attempt = 0; $attempt < 100; $attempt++) { $probe = @stream_socket_client('tcp://' . $address, $socketError, $socketMessage, 0.1); if (is_resource($probe)) { fclose($probe); break; } usleep(50000); }
    [$status] = $request('/api/fixture/notes'); $assert($status === 404, 'Inactive module exposes no HTTP API');
    [$exit, $text, $error] = $console(['activate', 'fixture.reports']); $assert($exit === 0, 'Module activation installs immutable migrations: ' . $text . $error);
    $body = "Unicode 🐈; quotes ' and \" and\nnewlines";
    $insert = $pdo->prepare('INSERT INTO lifecycle_notes(body) VALUES (?)'); $insert->execute([$body]);
    [$status, $json] = $request('/api/fixture/notes'); $assert($status === 200 && str_contains($json, 'Unicode'), 'Activation registers the real authorized product API');
    [$status, $html] = $request('/fixture/notes'); $assert($status === 200 && str_contains($html, 'Selected notes'), 'Activation adds module-owned translations');
    [$status, $html] = $request('/'); $assert($status === 200, 'Blog HTTP boot remains ready after optional activation');
    $assert(preg_match_all('~(?:src|href)="(/cache/assets/[^"?]+)~', $html, $assetMatches) > 0, 'Changed selection falls back from stale compiled assets');
    $delivered = '';
    foreach ($assetMatches[1] as $url) { [$assetStatus, $asset] = $request(html_entity_decode($url)); $assert($assetStatus === 200, 'Selected fallback asset is served'); $delivered .= $asset; }
    $assert(str_contains($delivered, 'lifecycleNotesLoaded'), 'Activated module JavaScript is delivered');
    [$exit, $text, $error] = $console(['task', 'fixture.notes.tick']); $assert($exit === 0 && (int) $pdo->query('SELECT ticks FROM lifecycle_notes')->fetchColumn() === 1, 'Activated scheduled contribution runs through the shared CLI: ' . $text . $error);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM scheduler_runs WHERE task = 'fixture.notes.tick' AND status = 'completed' AND finished_at IS NOT NULL")->fetchColumn() === 1, 'CLI records persistent core execution history without Scheduler');
    [$exit] = $console(['task', 'fixture.notes.tick', '--unknown=1']);
    $assert($exit !== 0 && (int) $pdo->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn() === 1, 'Invalid task options fail before creating a run');

    [$exit] = $console(['deactivate', 'fixture.notes']); $assert($exit !== 0, 'Active dependents block deactivation');
    [$exit, $text, $error] = $console(['deactivate', 'fixture.reports', 'fixture.notes']); $assert($exit === 0 && $pdo->query('SELECT body FROM lifecycle_notes')->fetchColumn() === $body, 'Deactivation retains data and schema history: ' . $text . $error);
    [$exit] = $console(['task', 'fixture.notes.tick']); $assert($exit !== 0 && (int) $pdo->query('SELECT ticks FROM lifecycle_notes')->fetchColumn() === 1, 'Inactive contributions cannot execute');
    [$status] = $request('/api/fixture/notes'); $assert($status === 404, 'Deactivation removes HTTP routes on the next request');
    $state = json_decode((string) file_get_contents($root . '/storage/deployment.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(!in_array('fixture.notes', $state['active'], true) && in_array('fixture.notes', $state['installed'], true), 'Activation and installation are separate states');
    $pdo->exec("INSERT INTO users(id, username, password, role, status) VALUES (2, 'deletion_fixture', 'fixture', 'user', 'active')");
    [$exit, $text, $error] = $run([PHP_BINARY, $fixture . '/delete-worker.php', $root], $root);
    $assert($exit === 0 && is_file($root . '/storage/notes-deleted-2.txt') && is_file($root . '/storage/reports-deleted-2.txt'), 'Inactive installed data owners participate in account deletion: ' . $text . $error);
    // Update inactive data, then activate and update again with the old provider already cached in PHP.
    $makeModule('notes', 2, ['tinycat.accounts']);
    $versionFile = $candidate . '/App/Core/Support/Version.php'; $versionSource = (string) file_get_contents($versionFile);
    file_put_contents($versionFile, str_replace("CURRENT = '6.0.0-dev.10'", "CURRENT = '6.0.0-dev.11'", $versionSource));
    $build('6.0.0-dev.11', $workspace . '/v2');
    $update = static function (string $directory) use ($console): array {
        $manifest = json_decode((string) file_get_contents($directory . '/tinycat-blog-update.json'), true, 512, JSON_THROW_ON_ERROR);
        return $console(['update', $directory . '/tinycat-blog-update.json', $directory . '/tinycat-blog-update.sig', $directory . '/' . $manifest['package']]);
    };
    [$exit, $text, $error] = $update($workspace . '/v2/update'); $assert($exit === 0, 'Signed inactive-module update: ' . $text . $error);
    $packagePhase = 'after-core-update'; require $fixture . '/package-cases.php';
    $assert((int) $pdo->query("SELECT version FROM module_installations WHERE module_id = 'fixture.notes'")->fetchColumn() === 2 && $pdo->query('SELECT body FROM lifecycle_notes')->fetchColumn() === $body, 'Inactive module migrates without losing data');
    [$exit, $text, $error] = $console(['activate', 'fixture.notes']); $assert($exit === 0, 'Updated inactive module can be activated: ' . $text . $error);
    $makeModule('notes', 3, ['tinycat.accounts']);
    file_put_contents($versionFile, str_replace("CURRENT = '6.0.0-dev.10'", "CURRENT = '6.0.0-dev.12'", $versionSource));
    $build('6.0.0-dev.12', $workspace . '/v3');
    $manifest = json_decode((string) file_get_contents($workspace . '/v3/update/tinycat-blog-update.json'), true, 512, JSON_THROW_ON_ERROR);
    [$exit, $text, $error] = $run([PHP_BINARY, $fixture . '/update-worker.php', $root, $workspace . '/v3/update/tinycat-blog-update.json', $workspace . '/v3/update/tinycat-blog-update.sig', $workspace . '/v3/update/' . $manifest['package']], $root);
    $assert($exit === 0 && (json_decode($text, true)['cached_provider_version'] ?? null) === 2, 'Update uses signed migrations while the old PHP provider is cached: ' . $text . $error);
    $assert((int) $pdo->query('SELECT revision_three FROM lifecycle_notes')->fetchColumn() === 3, 'Cached old provider cannot suppress the next migration');
    // A valid signature does not authorize a different product or a fresh-install artifact.
    $foreign = $manifest; $foreign['distribution']['product'] = 'fixture.other';
    $foreignPath = $workspace . '/foreign.json'; $signed = json_encode($foreign, JSON_THROW_ON_ERROR); file_put_contents($foreignPath, $signed); file_put_contents($workspace . '/foreign.sig', base64_encode(sodium_crypto_sign_detached($signed, $secret)));
    [$exit] = $console(['update', $foreignPath, $workspace . '/foreign.sig', $workspace . '/v3/update/' . $manifest['package']]);
    $assert($exit !== 0 && !is_file($root . '/storage/maintenance.json'), 'Cross-product update is rejected before changes');
    [$exit] = $console(['update', $workspace . '/v1/install/tinycat-blog-install.json', $workspace . '/v1/install/tinycat-blog-install.sig', $workspace . '/v1/install/' . $installManifest['package']]);
    $assert($exit !== 0, 'Fresh-install artifacts cannot overwrite an installed product');
    $stateBytes = file_get_contents($root . '/storage/deployment.json');
    $makeModule('notes', 4, ['tinycat.accounts']);
    file_put_contents($candidate . '/App/Console.php', "\n// New managed CLI entry for recovery regression.\n", FILE_APPEND);
    file_put_contents($versionFile, str_replace("CURRENT = '6.0.0-dev.10'", "CURRENT = '6.0.0-dev.13'", $versionSource));
    $build('6.0.0-dev.13', $workspace . '/v4');
    if (PHP_OS_FAMILY === 'Windows') {
        $replacement = json_decode((string) file_get_contents($workspace . '/v4/update/tinycat-blog-update.json'), true, 512, JSON_THROW_ON_ERROR);
        [$exit, $text, $error] = $run([PHP_BINARY, $root . '/App/Console.php', 'update', $workspace . '/v4/update/tinycat-blog-update.json', $workspace . '/v4/update/tinycat-blog-update.sig', $workspace . '/v4/update/' . $replacement['package']], $root);
        $assert($exit !== 0 && !is_file($root . '/storage/maintenance.json') && (int) $pdo->query("SELECT version FROM module_installations WHERE module_id = 'fixture.notes'")->fetchColumn() === 3, 'Windows refuses replacement of a running managed CLI before DB or file changes');
    }
    [$exit, $text, $error] = $update($workspace . '/v4/update');
    $assert($exit !== 0 && str_contains($error, 'Injected migration failure') && is_file($root . '/storage/maintenance.json'), 'Failed migration keeps maintenance and recovery journal: ' . $text . $error);
    $operation = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 512, JSON_THROW_ON_ERROR);
    [$exit, $text, $error] = $console(['rollback', $operation['backup']]); $assert($exit === 0, 'Failed migration restores the signed previous files and DB: ' . $text . $error);
    $assert(!$pdo->query("SELECT name FROM sqlite_master WHERE name = 'failed_migration'")->fetchColumn() && (int) $pdo->query("SELECT version FROM module_installations WHERE module_id = 'fixture.notes'")->fetchColumn() === 3, 'Rollback removes partial DDL and restores schema metadata');
    $assert(file_get_contents($root . '/storage/deployment.json') === $stateBytes && $pdo->query('SELECT body FROM lifecycle_notes')->fetchColumn() === $body, 'Rollback restores activation state and retained data byte for byte');
    // An explicit interruption leaves the previous state and a complete recoverable snapshot.
    [$exit] = $run([PHP_BINARY, $fixture . '/interrupt-worker.php', $root], $root); $assert($exit === 23 && is_file($root . '/storage/maintenance.json'), 'Interruption retains maintenance and the operation journal');
    [$exit] = $console(['deactivate', 'fixture.notes']); $assert($exit !== 0, 'Interrupted operations cannot be silently replaced');
    $operation = json_decode((string) file_get_contents($root . '/storage/updates/operation.json'), true, 512, JSON_THROW_ON_ERROR);
    $snapshot = $root . '/storage/updates/backups/' . $operation['backup'] . '/database.sqlite';
    $snapshotBytes = file_get_contents($snapshot); file_put_contents($snapshot, 'corrupt', FILE_APPEND);
    [$exit] = $console(['rollback', $operation['backup']]);
    $assert($exit !== 0 && $pdo->query("SELECT name FROM sqlite_master WHERE name = 'interrupted_module'")->fetchColumn() === 'interrupted_module', 'Corrupt backup is rejected before changing the live DB');
    file_put_contents($snapshot, $snapshotBytes);
    $configBytes = file_get_contents($root . '/config.php');
    $otherConfig = $config; $otherConfig['database']['dsn'] = 'sqlite:' . $root . '/other.sqlite';
    file_put_contents($root . '/config.php', '<?php return ' . var_export($otherConfig, true) . ';');
    [$exit, $text, $error] = $console(['rollback', $operation['backup']]);
    $assert($exit !== 0 && str_contains($error, 'snapshot destination'), 'Rollback refuses a changed database destination');
    file_put_contents($root . '/config.php', $configBytes);
    [$exit, $text, $error] = $console(['rollback', $operation['backup']]); $assert($exit === 0, 'Interrupted operation restores through the CLI: ' . $text . $error);
    $assert(!$pdo->query("SELECT name FROM sqlite_master WHERE name = 'interrupted_module'")->fetchColumn() && $pdo->query('SELECT body FROM lifecycle_notes')->fetchColumn() === $body, 'Rollback removes introduced tables and preserves original bytes');
    $assert(!is_file($root . '/storage/maintenance.json') && hash_file('sha256', $root . '/config.php') === $configHash, 'Recovery clears maintenance and leaves private configuration unchanged');
    echo "PASS signed product distribution, module activation, inactive upgrades, tasks and recovery ({$checks} checks)\n";
} finally {
    if (is_resource($http)) { proc_terminate($http); proc_close($http); }
    $insert = null; $pdo = null;
    $resolved = realpath($workspace); $temporary = realpath(sys_get_temp_dir());
    if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-distribution-')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($resolved);
    }
}
