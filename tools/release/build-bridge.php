<?php
declare(strict_types=1);

// Build both signed compatibility steps from the immutable shared-storage source.
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = dirname(__DIR__, 2);
$options = getopt('', ['output::', 'key::', 'assembly::']);
$output = (string) ($options['output'] ?? ($root . '/dist/compatibility-bridge'));
$assembly = (string) ($options['assembly'] ?? 'source');
if (!in_array($assembly, ['source', 'platform', 'blog'], true)) { throw new InvalidArgumentException('Use source, platform or blog for the bridge assembly.'); }
if (!str_starts_with($output, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $output) !== 1) { $output = $root . '/' . $output; }
$key = (string) ($options['key'] ?? ($root . '/storage/update-signing.key'));
$archive = $root . '/tests/fixtures/releases/bridge-6.0.0-dev.9.1-source.zip';
if (hash_file('sha256', $archive) !== '9620cebe0f1fb03d6a49673ebc3daef43a54e53800ffdaedc1bdb660cfeb3df5') { throw new RuntimeException('The frozen compatibility source changed.'); }
$temporary = $root . '/storage/bridge-build-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700, true)) { throw new RuntimeException('Cannot prepare bridge build directory.'); }
try {
    $zip = new PharData($archive); $zip->extractTo($temporary); unset($zip);
    $versionPath = $temporary . '/App/Core/Support/Version.php';
    $source = (string) file_get_contents($versionPath);
    if (!str_contains($source, "CURRENT = '6.0.0-dev.9.1'")) { throw new RuntimeException('Unexpected frozen bridge version.'); }
    foreach (['bootstrap' => '6.0.0-dev.9.1', 'checkpoint' => '6.0.0-dev.9.2'] as $phase => $version) {
        if (file_put_contents($versionPath, str_replace("CURRENT = '6.0.0-dev.9.1'", "CURRENT = '{$version}'", $source), LOCK_EX) === false) { throw new RuntimeException('Cannot prepare bridge version.'); }
        $command = [PHP_BINARY, $temporary . '/tools/release/build-update.php', '--version=' . $version, '--assembly=' . $assembly, '--allow-dirty', '--key=' . $key, '--output=' . $output . '/' . $phase];
        if ($phase === 'bootstrap') {
            $command[] = '--without-migrations';
            if ($assembly === 'source') { $command[] = '--minimum-version=5.16.0'; }
        } else { $command[] = '--minimum-version=6.0.0-dev.9.1'; }
        passthru(implode(' ', array_map(escapeshellarg(...), $command)), $exit);
        if ($exit !== 0) { throw new RuntimeException('Compatibility ' . $phase . ' build failed.'); }
    }
} finally {
    $resolved = realpath($temporary); $storage = realpath($root . '/storage');
    if ($resolved !== false && $storage !== false && str_starts_with($resolved, $storage . DIRECTORY_SEPARATOR . 'bridge-build-')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($resolved);
    }
}
