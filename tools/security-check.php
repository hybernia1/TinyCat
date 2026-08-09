<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);

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

$failures = [];
$patterns = [
    'private key' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'GitHub token' => '/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{50,})\b/',
    'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
    'Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
];
$textExtensions = ['css', 'htaccess', 'html', 'ini', 'js', 'json', 'md', 'neon', 'php', 'txt', 'xml', 'yaml', 'yml'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('~^(?:\.git|dist|storage|uploads|vendor|tests/security)(?:/|$)~', $relative) === 1) continue;
    if ($relative === 'config.php' || !in_array(strtolower($file->getExtension()), $textExtensions, true)) continue;
    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) continue;
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $source) === 1) $failures[] = "{$relative}: possible {$label}";
    }
}

$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
if (!is_array($lock)) {
    $failures[] = 'composer.lock is missing or invalid';
} elseif (($lock['packages'] ?? null) !== []) {
    $failures[] = 'production Composer dependencies are forbidden; use require-dev only';
}

$deletions = json_decode((string) file_get_contents($root . '/tools/update-deletions.json'), true);
if (!is_array($deletions)) {
    $failures[] = 'tools/update-deletions.json is invalid';
} else {
    foreach ($deletions as $version => $paths) {
        foreach ((array) $paths as $path) {
            $path = (string) $path;
            if ($managedPath($path) !== $path) {
                $failures[] = "unsafe deletion path in {$version}: {$path}";
            }
            if (preg_match('~^(?:config\.php|storage|uploads|tests|tools|vendor|\.git)(?:/|$)~', $path) === 1) {
                $failures[] = "protected deletion path in {$version}: {$path}";
            }
        }
    }
}

if ($failures !== []) {
    foreach (array_unique($failures) as $failure) fwrite(STDERR, "FAIL {$failure}\n");
    exit(1);
}

echo "PASS repository secret, package-path and production-dependency checks\n";
