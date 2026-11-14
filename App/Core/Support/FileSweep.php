<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Resumable directory walk. Deletions are bounded separately from directory visits. */
final class FileSweep
{
    /** The callback must process the whole batch, and must not follow symbolic links.
     * @param callable(list<\SplFileInfo>): array{changed: int, has_more: bool} $consume
     * @param (callable(string): bool)|null $allowDirectory
     * @return array{changed: int, has_more: bool} */
    public static function run(string $directory, int $budget, callable $consume, string $scope = 'files', bool $recursive = true, ?callable $allowDirectory = null, bool $pruneEmpty = false): array
    {
        $root = realpath($directory);
        if ($root === false || is_link($directory)) { return ['changed' => 0, 'has_more' => false]; }
        $state = Cache::file(hash('sha256', $root . ':' . $scope) . '.json', 'cleanup-cursors');
        if (!Storage::ensureDirectory(dirname($state))) { throw new \RuntimeException('Unable to create cleanup cursor directory.'); }
        $lock = fopen($state . '.lock', 'c+');
        if ($lock === false) { throw new \RuntimeException('Unable to open cleanup cursor lock.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return ['changed' => 0, 'has_more' => true]; }
            $saved = is_file($state) ? json_decode((string) file_get_contents($state), true) : null;
            $stack = is_array($saved) && array_is_list($saved) && count($saved) <= 16 ? $saved : [['path' => '', 'offset' => 0]];
            $files = [];
            $visited = 0;
            while ($stack !== [] && $visited < max(1, $budget)) {
                $index = count($stack) - 1;
                $frame = $stack[$index];
                $relative = $frame['path'] ?? null;
                $offset = $frame['offset'] ?? null;
                if (!is_string($relative) || !is_int($offset) || $offset < 0
                    || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/')
                    || in_array('..', explode('/', $relative), true)) {
                    throw new \RuntimeException('Invalid cleanup cursor.');
                }
                $path = $root . ($relative === '' ? '' : DIRECTORY_SEPARATOR . $relative);
                if ($relative !== '' && $allowDirectory !== null && !$allowDirectory($relative)) { array_pop($stack); continue; }
                $ancestor = $root;
                foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
                    $ancestor .= DIRECTORY_SEPARATOR . $segment;
                    if (is_link($ancestor)) { array_pop($stack); continue 2; }
                }
                $resolved = realpath($path);
                if ($resolved === false || is_link($path) || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) { array_pop($stack); continue; }
                $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
                $exhausted = false;
                try { $iterator->seek($offset); } catch (\OutOfBoundsException) { $exhausted = true; }
                while (!$exhausted && $iterator->valid() && $visited < max(1, $budget)) {
                    $file = $iterator->current();
                    if (!$file instanceof \SplFileInfo) { throw new \RuntimeException('Invalid directory entry.'); }
                    $stack[$index]['offset']++;
                    $visited++;
                    if (!$file->isLink()) {
                        if ($recursive && $file->isDir() && count($stack) < 16) {
                            $child = ($relative === '' ? '' : $relative . '/') . $file->getFilename();
                            if ($allowDirectory === null || $allowDirectory($child)) {
                                $stack[] = ['path' => $child, 'offset' => 0];
                                continue 2;
                            }
                        }
                        if ($file->isFile()) { $files[] = $file; }
                    }
                    $iterator->next();
                }
                if ($exhausted || !$iterator->valid()) {
                    array_pop($stack);
                    if ($pruneEmpty && $relative !== '' && @rmdir($path) && $stack !== []) {
                        $parent = count($stack) - 1;
                        $stack[$parent]['offset'] = max(0, $stack[$parent]['offset'] - 1);
                    }
                }
            }
            $result = $consume($files);
            // Numeric directory positions shift after removal. Correct active frames.
            foreach ($files as $file) {
                if (file_exists($file->getPathname())) { continue; }
                foreach ($stack as &$frame) {
                    $parent = $root . ($frame['path'] === '' ? '' : DIRECTORY_SEPARATOR . $frame['path']);
                    if ($parent === $file->getPath()) { $frame['offset'] = max(0, $frame['offset'] - 1); }
                }
                unset($frame);
            }
            if (!Storage::write($state, json_encode($stack === [] ? [['path' => '', 'offset' => 0]] : $stack, JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Unable to save cleanup cursor.');
            }
            return ['changed' => $result['changed'], 'has_more' => $stack !== [] || $result['has_more']];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function remove(string $path): bool
    {
        if (is_link($path) || !is_file($path)) { return false; }
        if (!@unlink($path)) { throw new \RuntimeException('Unable to remove cleanup file.'); }
        return true;
    }
}
