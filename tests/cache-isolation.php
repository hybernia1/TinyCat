<?php
declare(strict_types=1);

// Child HTTP servers inherit this directory. Never share a fixture cache with
// the developer's running app, even when a fixture replaces raw configuration.
if (getenv('TINYCAT_CACHE_DIRECTORY') === false || getenv('TINYCAT_CACHE_DIRECTORY') === '') {
    $testCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tinycat-test-cache-' . bin2hex(random_bytes(12));
    if (!mkdir($testCache, 0700, true)) {
        throw new RuntimeException('Unable to create the isolated test cache.');
    }
    putenv('TINYCAT_CACHE_DIRECTORY=' . $testCache);
    register_shutdown_function(static function () use ($testCache): void {
        if (!is_dir($testCache)) { return; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testCache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($testCache);
    });
}
