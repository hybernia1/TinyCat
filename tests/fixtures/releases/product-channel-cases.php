<?php
declare(strict_types=1);

return static function (string $root, string $temporary, string $version, string $keyPath, string $secretKey, string $publicKey, string $socialOutput): void {
    $coreOutput = $temporary . '/core';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/release/build-update.php')
        . ' --assembly=core --both --allow-dirty --version=' . escapeshellarg($version)
        . ' --output=' . escapeshellarg($coreOutput) . ' --key=' . escapeshellarg($keyPath);
    exec($command . ' 2>&1', $log, $exit);
    if ($exit !== 0) { throw new RuntimeException('Core artifact build failed: ' . implode(' ', $log)); }
    $installed = $temporary . '/core-installed'; mkdir($installed, 0700);
    $archive = new PharData($coreOutput . '/install/tinycat.core-' . $version . '-install.zip'); $archive->extractTo($installed); unset($archive);
    $core = json_decode((string) file_get_contents($coreOutput . '/update/tinycat.core-update.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($core['distribution']['selection'] !== [] || $core['distribution']['active'] !== [] || array_any(array_keys($core['files']), static fn (string $path): bool => str_starts_with($path, 'App/Modules/'))) { throw new RuntimeException('Core package contains optional modules.'); }
    // A valid custom product uses the same framework and a distinct signed artifact name.
    $custom = $core; $custom['distribution']['product'] = 'vendor.crm'; $custom['distribution']['artifact'] = 'crm-runtime';
    $channel = $temporary . '/channel'; mkdir($channel, 0700);
    foreach ([$socialOutput, $coreOutput . '/update'] as $directory) {
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file) && in_array(pathinfo($file, PATHINFO_EXTENSION), ['json', 'sig', 'zip'], true)) { copy($file, $channel . '/' . basename($file)); } }
    }
    $custom['package'] = 'crm-runtime-' . $version . '.zip'; $package = $channel . '/' . $custom['package'];
    copy($coreOutput . '/update/' . $core['package'], $package);
    $descriptor = json_encode($custom['distribution'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $zip = new PharData($package); $zip['App/distribution.json'] = $descriptor; unset($zip); clearstatcache(true, $package);
    $custom['files']['App/distribution.json'] = hash('sha256', $descriptor);
    $custom['size'] = filesize($package); $custom['sha256'] = hash_file('sha256', $package);
    $json = json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($channel . '/crm-runtime-update.json', $json);
    file_put_contents($channel . '/crm-runtime-update.sig', base64_encode(sodium_crypto_sign_detached($json, $secretKey)) . "\n");
    // Give the installed fixture a genuinely older runtime version without touching source files.
    $runtime = $installed . '/App/Core/Support/Version.php';
    $installedVersion = (string) $core['minimum_version'];
    if (!version_compare($installedVersion, $version, '<')) { throw new RuntimeException('The product-channel fixture requires a supported older runtime.'); }
    file_put_contents($runtime, str_replace("CURRENT = '" . $version . "'", "CURRENT = '" . $installedVersion . "'", (string) file_get_contents($runtime)));
    $worker = $root . '/tests/fixtures/releases/product-channel.php';
    $run = static function (array $input, bool $success = true) use ($temporary, $installed, $publicKey, $worker): string {
        $file = $temporary . '/channel-input.json';
        file_put_contents($file, json_encode($input + ['root' => $installed, 'public_key' => base64_encode($publicKey)], JSON_THROW_ON_ERROR));
        $output = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
        if (($exit === 0) !== $success) { throw new RuntimeException('Product channel worker: ' . implode(' ', $output)); }
        return implode("\n", $output);
    };
    foreach ([$core, $custom] as $candidate) {
        file_put_contents($installed . '/App/distribution.json', json_encode($candidate['distribution'], JSON_THROW_ON_ERROR));
        $assets = []; $network = [];
        foreach (glob($channel . '/*') ?: [] as $file) {
            $name = basename($file); $url = 'https://github.com/hybernia1/TinyCat/releases/download/v' . $version . '/' . $name;
            $assets[] = ['name' => $name, 'browser_download_url' => $url]; $network[$url] = $file;
        }
        $release = $temporary . '/product-release.json';
        file_put_contents($release, json_encode(['assets' => $assets, 'html_url' => 'https://github.com/hybernia1/TinyCat/releases/tag/v' . $version], JSON_THROW_ON_ERROR));
        $network['https://api.github.com/repos/hybernia1/TinyCat/releases/latest'] = $release;
        $checked = json_decode($run(['network' => $network]), true, 512, JSON_THROW_ON_ERROR);
        if (!$checked['available'] || !$checked['compatible'] || $checked['product'] !== $candidate['distribution']['product']) { throw new RuntimeException('Updater selected the wrong product from a shared release.'); }
        $run(['verify' => $channel, 'product' => $candidate['distribution']['product']]);
    }
    file_put_contents($installed . '/App/distribution.json', json_encode($core['distribution'], JSON_THROW_ON_ERROR));
    $run(['verify' => $coreOutput . '/install', 'product' => 'tinycat.core', 'fresh' => true]);
    copy($channel . '/tinycat.core-update.json', $channel . '/duplicate-update.json');
    $run(['verify' => $channel, 'product' => 'tinycat.core'], false); unlink($channel . '/duplicate-update.json');
    $releaseBytes = (string) file_get_contents($release);
    $missing = json_decode($releaseBytes, true, 512, JSON_THROW_ON_ERROR);
    $missing['assets'] = array_values(array_filter($missing['assets'], static fn (array $asset): bool => !in_array($asset['name'], ['tinycat.core-update.json', 'tinycat.core-update.sig'], true)));
    file_put_contents($release, json_encode($missing, JSON_THROW_ON_ERROR));
    if (!str_contains($run(['network' => $network], false), 'latest release does not contain')) { throw new RuntimeException('A missing core channel did not fail explicitly.'); }
    file_put_contents($release, $releaseBytes);
    // A genuine Social signature under a Core asset name must never change product identity.
    $coreUrl = 'https://github.com/hybernia1/TinyCat/releases/download/v' . $version . '/tinycat.core-update';
    $network[$coreUrl . '.json'] = $channel . '/tinycat-update.json'; $network[$coreUrl . '.sig'] = $channel . '/tinycat-update.sig';
    $checked = json_decode($run(['network' => $network, 'reject_install' => true]), true, 512, JSON_THROW_ON_ERROR);
    if ($checked['compatible'] || !$checked['blocked'] || $checked['installed_product'] !== 'tinycat.core') { throw new RuntimeException('Cross-product update was not refused.'); }
    echo "PASS shared release channels, core/custom artifacts, generic verification and cross-product rejection\n";
};
