<?php
declare(strict_types=1);

/** @param array<string, string> $prepared */
return static function (string $directory, array $prepared): void {
    foreach ($prepared as $name => $source) {
        $target = $directory . '/' . $name;
        if ($name === '' || basename($name) !== $name || !is_file($source) || is_link($source)
            || is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new RuntimeException('Invalid prepared artifact: ' . $name);
        }
    }
    $lock = fopen($directory . '/.build.lock', 'c');
    if ($lock === false) { throw new RuntimeException('Unable to open artifact publication lock.'); }
    $backups = []; $installed = [];
    try {
        if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Unable to lock artifact publication.'); }
        foreach ($prepared as $name => $source) {
            $target = $directory . '/' . $name;
            if (!is_file($target)) { continue; }
            $backup = $source . '.previous';
            if (!rename($target, $backup)) { throw new RuntimeException('Unable to preserve previous artifact: ' . $name); }
            $backups[$target] = $backup;
        }
        foreach ($prepared as $name => $source) {
            $target = $directory . '/' . $name;
            if (!rename($source, $target)) { throw new RuntimeException('Unable to publish artifact: ' . $name); }
            $installed[] = $target;
        }
    } catch (Throwable $error) {
        foreach ($installed as $target) { if (is_file($target)) { unlink($target); } }
        foreach ($backups as $target => $backup) {
            if (!rename($backup, $target)) { throw new RuntimeException('Unable to restore previous artifact; backup retained at ' . $backup, 0, $error); }
        }
        throw $error;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    foreach ($backups as $backup) { unlink($backup); }
};
