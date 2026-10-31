<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/bootstrap.php';

$temporaryRoot = $root . '/storage/package-artifact-' . bin2hex(random_bytes(6));
$output = $temporaryRoot . '/dist-a';
$repeatedOutput = $temporaryRoot . '/dist-b';
$keyPath = $temporaryRoot . '/signing.key';
$failures = [];
$names = [];
$managedPath = static function (string $path): string {
    $path = str_replace('\\', '/', trim($path));

    if ($path === ''
        || str_starts_with($path, '/')
        || preg_match('~^[A-Za-z]:/~', $path) === 1
        || preg_match('~(?:^|/)\.\.?(/|$)~', $path) === 1
        || preg_match('~^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$~', $path) !== 1
    ) {
        return '';
    }

    return $path;
};
$removeTree = static function (string $path) use (&$removeTree, $temporaryRoot): void {
    $temporary = realpath($temporaryRoot);
    $resolved = realpath($path);

    if (!is_string($temporary) || !is_string($resolved) || !str_starts_with($resolved, $temporary)) {
        return;
    }

    foreach (scandir($resolved) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $resolved . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) && !is_link($child) ? $removeTree($child) : unlink($child);
    }

    rmdir($resolved);
};

try {
    if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
        throw new RuntimeException('Unable to create the package test directory.');
    }

    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    file_put_contents($keyPath, base64_encode($secretKey) . "\n", LOCK_EX);
    $version = \TinyCat\Support\Version::CURRENT;
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update.php')
        . ' --version=' . escapeshellarg($version)
        . ' --allow-dirty'
        . (in_array('--fresh-install', $argv, true) ? ' --fresh-install' : ' --without-migrations')
        . ' --output=' . escapeshellarg($output)
        . ' --key=' . escapeshellarg($keyPath);
    exec($command . ' 2>&1', $buildOutput, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Package builder failed: ' . implode(' ', $buildOutput));
    }

    $unsupportedOutput = [];
    exec($command . ' --minimum-version=5.15.0 2>&1', $unsupportedOutput, $unsupportedExit);
    if ($unsupportedExit === 0 || !str_contains(implode(' ', $unsupportedOutput), 'Minimum update version must be between')) {
        throw new RuntimeException('Package builder must reject unsupported update baselines.');
    }

    $repeatedCommand = str_replace(
        '--output=' . escapeshellarg($output),
        '--output=' . escapeshellarg($repeatedOutput),
        $command
    );
    exec($repeatedCommand . ' 2>&1', $repeatedBuildOutput, $repeatedExitCode);

    if ($repeatedExitCode !== 0) {
        throw new RuntimeException('Repeated package build failed: ' . implode(' ', $repeatedBuildOutput));
    }

    $freshInstall = in_array('--fresh-install', $argv, true);
    $packageName = 'tinycat-' . $version . ($freshInstall ? '-install' : '') . '.zip';
    $manifestName = $freshInstall ? 'tinycat-install' : 'tinycat-update';
    $package = $output . '/' . $packageName;
    $manifestPath = $output . '/' . $manifestName . '.json';
    $signaturePath = $output . '/' . $manifestName . '.sig';
    $repeatedPackage = $repeatedOutput . '/' . $packageName;
    $manifestJson = (string) file_get_contents($manifestPath);
    $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['minimum_version'] ?? null) !== \TinyCat\Support\Version::MINIMUM_UPDATE) {
        throw new RuntimeException('Package builder must default to the supported update baseline.');
    }
    $signature = base64_decode(trim((string) file_get_contents($signaturePath)), true);

    if (!is_string($signature) || !sodium_crypto_sign_verify_detached($signature, $manifestJson, $publicKey)) {
        $failures[] = 'Generated artifact signature could not be verified.';
    }

    $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);

    if (hash_file('sha256', $package) !== hash_file('sha256', $repeatedPackage)) {
        $failures[] = 'Repeated builds from the same source are not byte-identical.';
    }

    if (($manifest['version'] ?? null) !== $version) {
        $failures[] = 'Artifact target version does not match the current release.';
    }

    if (($manifest['migrations'] ?? null) !== []) {
        $failures[] = 'Stage 1 artifact fixture unexpectedly declares migrations.';
    }

    if (in_array('--fresh-install', $argv, true) && ($manifest['delete'] ?? null) !== []) {
        $failures[] = 'Fresh installation artifact unexpectedly declares update deletions.';
    }

    $archive = new PharData($package);
    $iterator = new RecursiveIteratorIterator($archive);

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen('phar://' . $package) + 1));
        $names[] = $relative;

        if ($managedPath($relative) !== $relative) {
            $failures[] = "Unsafe artifact path: {$relative}";
        }

        if (preg_match('~^(?:\.git|\.github|dist|storage|tests|tools|uploads|vendor)(?:/|$)~', $relative) === 1) {
            $failures[] = "Development or private path in artifact: {$relative}";
        }

        if (in_array('--fresh-install', $argv, true) && str_starts_with($relative, 'migrations/')) {
            $failures[] = "Migration in fresh installation artifact: {$relative}";
        }

        $expectedHash = $manifest['files'][$relative] ?? null;

        if (!is_string($expectedHash) || hash('sha256', (string) $entry->getContent()) !== $expectedHash) {
            $failures[] = "Artifact file hash mismatch: {$relative}";
        }
    }

    sort($names, SORT_STRING);
    if (!in_array('--fresh-install', $argv, true)
        && !in_array('migrations/20261026_003_scheduler_runs.php', $names, true)
    ) {
        $failures[] = 'Updater bridge must retain authenticated migration files.';
    }
    $manifestNames = array_keys((array) ($manifest['files'] ?? []));
    sort($manifestNames, SORT_STRING);

    if ($manifestNames !== $names) {
        $failures[] = 'Manifest and archive file inventories differ.';
    }

    foreach (['composer.json', 'composer.lock', 'phpstan.neon', 'phpstan-baseline.neon', 'config.php'] as $file) {
        if (in_array($file, $names, true)) {
            $failures[] = "Development or private file in artifact: {$file}";
        }
    }

    foreach ($names as $name) {
        if (preg_match('~^docs/(?:translation-refactor|development/|baseline-|performance(?:[.-]|$)|release-2\.0\.26-monolith-plan|stage-[0-9]+)~', $name) === 1) {
            $failures[] = "Internal release evidence in artifact: {$name}";
        }
    }

    foreach (['App/Support/Version.php', 'App/Support/Localization.php', 'App/Updates/Manager.php', 'App/Bots/Importer.php', 'App/Bots/Administration.php', 'App/Bots/Pages.php', 'index.php', 'scheduled-tasks.php'] as $required) {
        if (!in_array($required, $names, true)) {
            $failures[] = "Required modular runtime file missing from artifact: {$required}";
        }
    }

    foreach ($names as $name) {
        if (str_starts_with($name, 'Extensions/') || in_array($name, ['App/Extension.php', 'Public/admin/extensions.php', 'docs/extensions.md'], true)) {
            $failures[] = 'Retired extension support in artifact: ' . $name;
        }
    }

    foreach (['App/Http/Api.php', 'App/Http/Request.php', 'App/Http/Response.php', 'App/Http/Validator.php', 'App/Database/Connection.php', 'App/Support/Config.php', 'App/Module.php', 'App/modules.php', 'assets/core/http.js', 'assets/core/dom.js', 'assets/ui/forms.js', 'assets/ui/dialogs.js'] as $required) {
        if (!in_array($required, $names, true)) {
            $failures[] = "Required module infrastructure missing from artifact: {$required}";
        }
    }

    foreach (['App/Users/Module.php', 'App/Users/Api.php', 'App/Users/Avatar.php', 'App/Users/Roles.php', 'App/Notifications/Module.php', 'App/Notifications/Api.php', 'App/Status/Module.php', 'App/Status/Api.php', 'App/Status/ImageStorage.php', 'App/Status/LinkParser.php', 'App/Status/Metadata.php', 'assets/ui/fields.js', 'assets/modules/users/views.js', 'assets/modules/status/views.js', 'assets/modules/notifications/views.js'] as $required) {
        if (!in_array($required, $names, true)) { $failures[] = 'Required domain runtime missing: ' . $required; }
    }
    foreach (['App/Core.php', 'App/functions.php', 'App/Api.php', 'App/Bots.php', 'App/BotAdmin.php', 'App/Captcha.php', 'App/Cache.php', 'App/Minifier.php', 'App/SiteIdentity.php', 'App/Sitemap.php', 'App/PackageManager.php', 'App/Notifications.php', 'App/Avatar.php', 'App/UserRoles.php', 'App/StatusImage.php', 'App/StatusLinks.php', 'App/LinkMetadata.php'] as $retired) {
        if (in_array($retired, $names, true)) { $failures[] = 'Moved implementation remains in artifact: ' . $retired; }
    }

    unset($entry, $iterator, $archive);
    sodium_memzero($secretKey);
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
} finally {
    if (is_dir($temporaryRoot)) {
        $removeTree($temporaryRoot);
    }
}

if ($failures !== []) {
    foreach (array_unique($failures) as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo 'PASS signed monolith artifact boundary (' . count($names) . " runtime files)\n";
