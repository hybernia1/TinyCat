<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$files = [];
foreach (['App', 'Extensions', 'Public', 'migrations', 'tests', 'tools'] as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach (glob($root . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
    if (basename($path) !== 'config.php') $files[] = $path;
}
sort($files, SORT_STRING);

$failed = [];
foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        $failed[] = $relative . ': ' . implode(' ', $output);
    }
    $output = [];
}

if ($failed !== []) {
    foreach ($failed as $failure) fwrite(STDERR, "FAIL {$failure}\n");
    exit(1);
}

echo 'PASS PHP 8.4 syntax lint (' . count($files) . " files)\n";
