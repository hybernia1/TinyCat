<?php
declare(strict_types=1);

namespace TinyCatTests;

/** Shared reporting for the existing runners; never loaded by the application. */
final class Execution
{
    private static bool $owner = false;
    private static string $directory;
    private static int $started;

    private static function initialize(): void
    {
        if (isset(self::$directory)) { return; }
        self::$started = hrtime(true);
        $inherited = getenv('TINYCAT_TEST_REPORT');
        if (is_string($inherited) && is_dir($inherited)) { self::$directory = $inherited; return; }
        self::$owner = true;
        self::$directory = dirname(__DIR__, 2) . '/storage/test-runs/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
        if (!mkdir(self::$directory, 0700, true)) { throw new \RuntimeException('Cannot create test report directory.'); }
        putenv('TINYCAT_TEST_REPORT=' . self::$directory);
        $root = dirname(__DIR__, 2); $inventoryStart = hrtime(true); $files = self::sourceFiles();
        $inventorySeconds = (hrtime(true) - $inventoryStart) / 1e9;
        $revision = []; exec('git rev-parse HEAD 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $revision);
        $node = []; exec('node --version', $node);
        $browserCandidates = array_values(array_filter([getenv('TINYCAT_TEST_CHROME'), 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'], static fn (mixed $path): bool => is_string($path) && is_file($path)));
        $playwright = getenv('TINYCAT_TEST_PLAYWRIGHT_MODULE') ?: $root . '/storage/ui-webkit-tools/node_modules/playwright';
        $package = is_file($playwright . '/package.json') ? json_decode((string) file_get_contents($playwright . '/package.json'), true, 512, JSON_THROW_ON_ERROR) : [];
        self::event(['type' => 'run', 'started_at' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'node' => $node[0] ?? null, 'os' => PHP_OS_FAMILY,
            'browser_candidates' => $browserCandidates, 'playwright_package' => $package['version'] ?? null,
            'pdo_drivers' => \PDO::getAvailableDrivers(), 'revision' => $revision[0] ?? null,
            'previous_report' => getenv('TINYCAT_TEST_PREVIOUS_REPORT') ?: null,
            'source_hash_algorithm' => 'git-blob-oid', 'inventory_seconds' => $inventorySeconds,
            'tree_sha256' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)), 'source_files' => $files]);
        echo 'Measured report: ' . self::$directory . "\n";
        register_shutdown_function(self::finish(...));
    }

    /** @param array<string, string> $items
     * @param list<string>|null $selected */
    public static function plan(array $items, ?array $selected = null): void
    {
        self::initialize();
        foreach ($items as $id => $label) { self::event(['type' => 'planned', 'id' => $id, 'label' => $label, 'parent' => self::parent(), 'selected' => $selected === null || in_array($id, $selected, true)]); }
    }

    /** @param array<string, string> $items @return array<string, string> */
    public static function select(array $items): array
    {
        $only = null;
        foreach ($_SERVER['argv'] ?? [] as $argument) { if (str_starts_with($argument, '--only=')) { $only = explode(',', substr($argument, 7)); } }
        if ($only === null) { return $items; }
        foreach ($only as $id) { if (!isset($items[$id])) { throw new \InvalidArgumentException('Unknown test ID: ' . $id); } }
        echo 'Selected ' . count($only) . ' of ' . count($items) . " entries; this is a partial run.\n";
        return array_intersect_key($items, array_flip($only));
    }

    public static function listing(): bool { return in_array('--list', $_SERVER['argv'] ?? [], true); }

    public static function run(string $id, string $label, string $command): int
    {
        self::initialize();
        $parent = self::parent(); $start = hrtime(true); $log = self::$directory . '/' . hash('sha256', ($parent ?? '') . ':' . $id) . '.log';
        self::event(['type' => 'started', 'id' => $id, 'parent' => $parent, 'command' => $command, 'started_at' => gmdate(DATE_ATOM)]);
        $environment = array_merge(getenv(), ['TINYCAT_TEST_PARENT' => $id]);
        $process = proc_open($command, [0 => STDIN, 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        $exit = 1;
        if (is_resource($process)) {
            $reader = fopen($log, 'rb');
            do {
                if (is_resource($reader)) { $chunk = stream_get_contents($reader); if (is_string($chunk) && $chunk !== '') { echo $chunk; } }
                $status = proc_get_status($process);
                if ($status['running']) { usleep(50000); }
            } while ($status['running']);
            if (is_resource($reader)) { echo stream_get_contents($reader); fclose($reader); }
            $closed = proc_close($process); $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        }
        $source = is_file($log) ? (string) file_get_contents($log) : '';
        preg_match_all('/^SKIP[^\r\n]*/m', $source, $matches);
        $skip = array_values(array_unique($matches[0]));
        $state = $exit !== 0 ? 'failed' : ($skip !== [] && preg_match('/^(?:PASS|\[OK\])/m', $source) !== 1 ? 'skipped' : 'passed');
        $seconds = (hrtime(true) - $start) / 1e9;
        self::event(['type' => 'finished', 'id' => $id, 'label' => $label, 'parent' => $parent, 'status' => $state,
            'seconds' => $seconds, 'exit_code' => $exit, 'skips' => $skip, 'finished_at' => gmdate(DATE_ATOM), 'log' => basename($log)]);
        printf("MEASURE %s %.3fs %s\n", $state, $seconds, $id);
        return $exit;
    }

    public static function finish(): void
    {
        if (!self::$owner) { return; }
        self::$owner = false;
        $events = []; $items = []; $metadata = [];
        foreach (file(self::$directory . '/events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR); $events[] = $event;
            if ($event['type'] === 'run') { $metadata = $event; continue; }
            $key = ($event['parent'] ?? '') . ':' . $event['id'];
            if ($event['type'] === 'planned') { $items[$key] = $event + ['status' => 'not-run']; }
            elseif ($event['type'] === 'started') { $items[$key] = array_merge($items[$key] ?? [], $event, ['status' => 'interrupted']); }
            elseif ($event['type'] === 'finished') { $items[$key] = array_merge($items[$key] ?? [], $event); }
        }
        $endFiles = self::sourceFiles();
        $sourceChanges = [];
        foreach (array_unique([...array_keys($metadata['source_files']), ...array_keys($endFiles)]) as $name) { if (($metadata['source_files'][$name] ?? null) !== ($endFiles[$name] ?? null)) { $sourceChanges[] = $name; } }
        $summary = [];
        foreach ($items as $item) { $summary[$item['status']] = ($summary[$item['status']] ?? 0) + 1; }
        $report = ['metadata' => $metadata, 'finished_at' => gmdate(DATE_ATOM), 'seconds' => (hrtime(true) - self::$started) / 1e9, 'summary' => $summary,
            'end_tree_sha256' => hash('sha256', json_encode($endFiles, JSON_THROW_ON_ERROR)), 'source_changes_during_run' => $sourceChanges, 'items' => array_values($items)];
        file_put_contents(self::$directory . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        echo 'Results: ' . self::$directory . "/report.json\n";
    }

    private static function parent(): ?string { $value = getenv('TINYCAT_TEST_PARENT'); return is_string($value) && $value !== '' ? $value : null; }

    /** Hash only versioned/nonignored source inputs; never walk local data or tool installations.
     * @return array<string, string> */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $listing = self::$directory . '/source-list.raw';
        self::git(['ls-files', '--cached', '--others', '--exclude-standard', '-z'], $listing);
        $paths = [];
        foreach (explode("\0", (string) file_get_contents($listing)) as $name) {
            if (preg_match('~^(?:App|Public|assets|lang|tests|tools|\.github|migrations)/~', $name) !== 1 && !in_array($name, ['index.php', 'scheduled-tasks.php', 'composer.json', 'composer.lock', 'phpstan.neon', '.htaccess', '.gitignore', '.gitattributes', 'README.md', 'LICENSE', 'docs/reference/api.md', 'docs/operations/updates.md', 'docs/operations/module-deployment.md'], true)) { continue; }
            if (is_file($root . '/' . $name) && !is_link($root . '/' . $name)) { $paths[$name] = true; }
        }
        ksort($paths); $names = array_keys($paths);
        $input = self::$directory . '/source-names.txt'; $output = self::$directory . '/source-oids.txt';
        file_put_contents($input, implode("\n", $names) . "\n");
        self::git(['hash-object', '--stdin-paths'], $output, $input);
        $hashes = file($output, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($hashes) !== count($names)) { throw new \RuntimeException('Incomplete source fingerprint.'); }
        foreach ($hashes as $hash) { if (preg_match('/^[a-f0-9]{40,64}$/D', $hash) !== 1) { throw new \RuntimeException('Invalid source fingerprint.'); } }
        return array_combine($names, $hashes);
    }

    /** @param list<string> $arguments */
    private static function git(array $arguments, string $output, ?string $input = null): void
    {
        $process = proc_open(['git', ...$arguments], [0 => $input !== null ? ['file', $input, 'r'] : ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $output, 'w'], 2 => ['file', self::$directory . '/git-inventory.log', 'a']], $pipes, dirname(__DIR__, 2));
        if (!is_resource($process) || proc_close($process) !== 0) { throw new \RuntimeException('Cannot fingerprint repository sources.'); }
    }
    /** @param array<string, mixed> $event */
    private static function event(array $event): void
    {
        if (file_put_contents(self::$directory . '/events.jsonl', json_encode($event, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) { throw new \RuntimeException('Cannot write test measurement.'); }
    }
}
