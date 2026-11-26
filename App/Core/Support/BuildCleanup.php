<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Retire generated release files only after their deployment and rollback references expire. */
final class BuildCleanup
{
    private const int GRACE = 172800;
    private const string PHP_NAME = '/^(?:components|[a-z0-9-]+)\.[a-f0-9]{24}\.php$/D';
    private const string ASSET_NAME = '/^[A-Za-z0-9_.-]+\.[a-f0-9]{24}\.(?:js|css|json|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf)$/D';

    /** @return array{changed: int, has_more: bool} */
    public static function run(int $batch): array
    {
        $entered = false;
        try {
            return \TinyCat\Core\Updates\Manager::runTask(static function () use ($batch, &$entered): array {
                $entered = true;
                return self::sweep($batch);
            });
        } catch (\RuntimeException $error) {
            if ($entered) { throw $error; }
            return ['changed' => 0, 'has_more' => true];
        }
    }

    /** @return array{changed: int, has_more: bool} */
    private static function sweep(int $batch): array
    {
        $root = Paths::basePath();
        $assets = $root . '/assets/build';
        $compiled = $root . '/App/Core/Support/compiled';
        $manifest = $assets . '/manifest.json';
        $index = $compiled . '/translations.php';
        // A source checkout has nothing to retire; an incomplete release must not be pruned.
        if (!is_file($manifest) && !is_file($index)) { return ['changed' => 0, 'has_more' => false]; }
        $keep = [];
        self::inventory($root, $keep, true);
        foreach (array_keys($keep) as $relative) {
            self::safePath($root . '/' . $relative);
            if (!is_file($root . '/' . $relative)) { throw new \RuntimeException('Incomplete current build cleanup inventory.'); }
        }
        if (max((int) filemtime($manifest), (int) filemtime($index)) > time() - self::GRACE) { return ['changed' => 0, 'has_more' => false]; }
        $backups = $root . '/storage/updates/backups';
        if (is_link($backups)) { throw new \RuntimeException('Unsafe build cleanup backup directory.'); }
        if (is_dir($backups)) {
            $visited = 0;
            foreach (new \FilesystemIterator($backups, \FilesystemIterator::SKIP_DOTS) as $backup) {
                if (!$backup instanceof \SplFileInfo) { throw new \RuntimeException('Invalid build cleanup backup entry.'); }
                if (++$visited > 1000 || $backup->isLink()) { throw new \RuntimeException('Unsafe build cleanup backup inventory.'); }
                if ($backup->isDir()) { self::inventory($backup->getPathname() . '/files', $keep, false); }
            }
        }
        // Resolve references inside current and rollback client payloads before deleting anything.
        $pending = array_keys($keep);
        $seen = [];
        while ($pending !== []) {
            $relative = array_pop($pending);
            if (isset($seen[$relative]) || !str_starts_with($relative, 'assets/build/')) { continue; }
            $seen[$relative] = true;
            $path = $root . '/' . $relative;
            if (!is_file($path)) { continue; } // A rollback may restore this file from its backup.
            self::safePath($path);
            if (!in_array(pathinfo($path, PATHINFO_EXTENSION), ['js', 'css', 'json'], true)) { continue; }
            $before = $keep;
            self::assetReferences(str_replace('\\/', '/', self::read($path)), $keep);
            foreach (array_diff_key($keep, $before) as $name => $_) { $pending[] = $name; }
        }
        $cutoff = time() - self::GRACE;
        $changed = 0; $more = false;
        foreach ([$assets => self::ASSET_NAME, $compiled => self::PHP_NAME] as $directory => $pattern) {
            self::safePath($directory);
            $result = FileSweep::run($directory, max(1, intdiv($batch, 2)), static function (array $files) use ($root, $pattern, $cutoff, $keep): array {
                $changed = 0;
                foreach ($files as $file) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    if (isset($keep[$relative]) || preg_match($pattern, $file->getFilename()) !== 1) { continue; }
                    clearstatcache(true, $file->getPathname());
                    $modified = @filemtime($file->getPathname());
                    if ($modified !== false && $modified < $cutoff && FileSweep::remove($file->getPathname())) { $changed++; }
                }
                return ['changed' => $changed, 'has_more' => false];
            }, 'release-builds', false);
            $changed += $result['changed']; $more = $more || $result['has_more'];
        }
        return ['changed' => $changed, 'has_more' => $more];
    }

    /** @param array<string, true> $keep */
    private static function inventory(string $root, array &$keep, bool $required): void
    {
        $manifest = $root . '/assets/build/manifest.json';
        $index = $root . '/App/Core/Support/compiled/translations.php';
        if ($required || is_file($manifest)) {
            $data = json_decode(self::read($manifest), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_array($data['assets'] ?? null) || !is_array($data['clients'] ?? null)
                || !is_string($data['server_components'] ?? null) || preg_match(self::PHP_NAME, $data['server_components']) !== 1) {
                throw new \RuntimeException('Invalid build cleanup asset manifest.');
            }
            $keep['App/Core/Support/compiled/' . $data['server_components']] = true;
            if ($required && !is_file($root . '/App/Core/Support/compiled/' . $data['server_components'])) { throw new \RuntimeException('Missing current build cleanup components.'); }
            self::assetReferences(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $keep);
        }
        if ($required || is_file($index)) {
            self::safePath($index);
            $data = (static fn (string $path): mixed => require $path)($index);
            if (!is_array($data) || !is_array($data['catalogs'] ?? null) || $data['catalogs'] === []) { throw new \RuntimeException('Invalid build cleanup translation index.'); }
            foreach ($data['catalogs'] as $name) {
                if (!is_string($name) || preg_match(self::PHP_NAME, $name) !== 1) { throw new \RuntimeException('Invalid build cleanup catalog path.'); }
                $keep['App/Core/Support/compiled/' . $name] = true;
                if ($required && !is_file($root . '/App/Core/Support/compiled/' . $name)) { throw new \RuntimeException('Missing current build cleanup catalog.'); }
            }
        }
    }

    /** @param array<string, true> $keep */
    private static function assetReferences(string $text, array &$keep): void
    {
        preg_match_all('~/assets/build/([A-Za-z0-9_.-]+)~', $text, $matches);
        foreach ($matches[1] as $name) {
            if (preg_match(self::ASSET_NAME, $name) !== 1) { throw new \RuntimeException('Invalid build cleanup asset path.'); }
            $keep['assets/build/' . $name] = true;
        }
    }

    private static function read(string $path): string
    {
        self::safePath($path);
        if (!is_file($path) || filesize($path) > 16777216) { throw new \RuntimeException('Missing or oversized build cleanup inventory.'); }
        $text = file_get_contents($path);
        if ($text === false) { throw new \RuntimeException('Unable to read build cleanup inventory.'); }
        return $text;
    }

    private static function safePath(string $path): void
    {
        for ($current = $path; $current !== dirname($current); $current = dirname($current)) {
            if (is_link($current)) { throw new \RuntimeException('Symbolic link in build cleanup inventory.'); }
        }
    }
}
