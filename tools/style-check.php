<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$fix = in_array('--fix', $argv, true);
$files = [];
$directories = ['App', 'Extensions', 'Public', 'migrations', 'tests', 'tools'];

foreach ($directories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (str_starts_with($relative, 'tests/security/')) continue;
        $files[$relative] = $file->getPathname();
    }
}
foreach (glob($root . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
    if (basename($path) === 'config.php') continue;
    $files[basename($path)] = $path;
}
ksort($files, SORT_STRING);

$failures = [];
$changed = 0;
foreach ($files as $relative => $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        $failures[] = "{$relative}: unreadable file";
        continue;
    }

    if ($fix) {
        $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $normalized = str_replace(["\r\n", "\r"], "\n", ltrim($source, "\xEF\xBB\xBF"));
        $lines = explode("\n", $normalized);
        foreach ($lines as &$line) $line = rtrim($line, " \t");
        unset($line);
        $normalized = rtrim(implode("\n", $lines), "\n") . "\n";
        $updated = $eol === "\n" ? $normalized : str_replace("\n", $eol, $normalized);
        if ($updated !== $source) {
            file_put_contents($path, $updated, LOCK_EX);
            $source = $updated;
            $changed++;
        }
    }

    $logical = str_replace(["\r\n", "\r"], "\n", $source);
    if (str_starts_with($source, "\xEF\xBB\xBF")) $failures[] = "{$relative}: UTF-8 BOM is not allowed";
    if (preg_match('//u', $source) !== 1) $failures[] = "{$relative}: invalid UTF-8";
    if (preg_match('/(?m)[ \t]+$/', $logical) === 1) $failures[] = "{$relative}: trailing whitespace";
    if (!str_ends_with($logical, "\n") || str_ends_with($logical, "\n\n")) {
        $failures[] = "{$relative}: file must end with exactly one newline";
    }
    if (preg_match('/^<\?php\ndeclare\(strict_types=1\);\n/', $logical) !== 1) {
        $failures[] = "{$relative}: PHP files must start with strict_types=1";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL {$failure}\n");
    fwrite(STDERR, "\nCode style: " . count($files) . ' files, ' . count($failures) . " failures.\n");
    exit(1);
}

echo 'PASS deterministic PHP style (' . count($files) . ' files' . ($fix ? ", {$changed} fixed" : '') . ")\n";
