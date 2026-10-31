<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/App/Support/Version.php';
$version = \TinyCat\Support\Version::CURRENT;
$options = getopt('', ['artifact::']);
$artifact = trim((string) ($options['artifact'] ?? ($root . '/dist/release-' . $version)));

if (!str_starts_with($artifact, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $artifact) !== 1) {
    $artifact = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $artifact);
}

$manifestPath = $artifact . DIRECTORY_SEPARATOR . 'tinycat-update.json';
$signaturePath = $artifact . DIRECTORY_SEPARATOR . 'tinycat-update.sig';
$temporaryRoot = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'artifact-preflight-' . bin2hex(random_bytes(6));
$installRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'install';
$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
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
$run = static function (array $command) use (&$checks, &$failures): void {
    $checks++;
    $escaped = implode(' ', array_map(escapeshellarg(...), $command));
    $output = [];
    exec($escaped . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        $failures[] = implode(' ', $command) . ': ' . implode(' ', $output);
    }
};

try {
    if (!is_file($manifestPath) || !is_file($signaturePath)) {
        throw new RuntimeException('Artifact manifest or signature is missing.');
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $packageName = basename((string) ($manifest['package'] ?? ''));
    $packagePath = $artifact . DIRECTORY_SEPARATOR . $packageName;
    $assert($packageName === 'tinycat-' . $version . '.zip' && is_file($packagePath), 'The versioned package is present.');
    $assert(($manifest['version'] ?? null) === $version, 'Manifest targets the current version.');
    $assert(($manifest['minimum_version'] ?? null) === \TinyCat\Support\Version::MINIMUM_UPDATE, 'Manifest uses the supported update baseline.');
    $assert(($manifest['minimum_php'] ?? null) === '8.4.0', 'Manifest retains the PHP 8.4 runtime floor.');
    foreach ((array) ($manifest['migrations'] ?? []) as $migration) {
        $assert(isset($manifest['files'][$migration]), 'Declared migration is included in the signed inventory: ' . $migration);
    }
    $assert(hash_file('sha256', $packagePath) === ($manifest['sha256'] ?? null), 'Package hash matches the signed manifest.');

    $verifyOutput = [];
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/verify-update.php') . ' ' . escapeshellarg($artifact) . ' 2>&1',
        $verifyOutput,
        $verifyExit
    );
    $assert($verifyExit === 0, 'Pinned release key verifies manifest and package: ' . implode(' ', $verifyOutput));

    if (!mkdir($installRoot, 0775, true) && !is_dir($installRoot)) {
        throw new RuntimeException('Unable to create artifact extraction directory.');
    }

    $archive = new PharData($packagePath);
    $archive->extractTo($installRoot, null, true);
    unset($archive);
    $inventory = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($installRoot, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($installRoot) + 1));
        $inventory[$relative] = hash_file('sha256', $entry->getPathname());

        if (str_ends_with($relative, '.php')) {
            $run([PHP_BINARY, '-l', $entry->getPathname()]);
        }
    }

    ksort($inventory, SORT_STRING);
    $expected = (array) ($manifest['files'] ?? []);
    ksort($expected, SORT_STRING);
    $assert($inventory === $expected, 'Extracted inventory and every file hash match the manifest.');

    foreach (array_keys($inventory) as $relative) {
        $assert(
            preg_match('~^(?:storage|uploads|tests|tools|vendor)(?:/|$)~', $relative) !== 1,
            'Artifact excludes development and runtime-private path: ' . $relative
        );
        $assert(
            preg_match('~^docs/(?:baseline-|performance-benchmark|release-2\.0\.26-monolith-plan|stage-[0-9]+)~', $relative) !== 1,
            'Artifact excludes internal release evidence: ' . $relative
        );
    }

    $core = (string) file_get_contents($installRoot . '/App/Support/Version.php');
    $assert(str_contains($core, "public const string CURRENT = '{$version}';"), 'Extracted runtime reports the current version.');
    $run([PHP_BINARY, $root . '/tests/http/public-route-smoke.php', '--root=' . $installRoot]);
    $run([PHP_BINARY, $root . '/tests/release/mysql-installer-rehearsal.php', '--root=' . $installRoot]);
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

    echo "\nArtifact preflight: {$checks} checks, " . count(array_unique($failures)) . " failures.\n";
    exit(1);
}

echo "PASS extracted {$version} artifact preflight ({$checks} checks, signed inventory, routes and fresh MySQL install)\n";
