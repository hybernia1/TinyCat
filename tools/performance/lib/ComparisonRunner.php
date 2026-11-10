<?php
declare(strict_types=1);

namespace TinyCat\Tools\Performance;

use Memcached;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class ComparisonRunner
{
    private const array TABLES = [
        'users', 'content', 'terms', 'content_tags', 'links', 'content_links', 'content_likes',
        'content_comments', 'comment_likes', 'user_followers', 'notifications',
        'content_reports',
    ];

    private const array MYSQL_STATUS = [
        'Questions', 'Com_select', 'Created_tmp_disk_tables', 'Created_tmp_tables',
        'Handler_read_rnd_next', 'Select_full_join', 'Select_scan', 'Bytes_sent', 'Bytes_received',
    ];

    private readonly HttpLoadRunner $http;
    /** @var array<string, string> */
    private array $originalConfigs = [];

    public function __construct(private readonly ComparisonOptions $options)
    {
        $this->http = new HttpLoadRunner();
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $installations = [
            $this->options->baselineLabel => $this->installation($this->options->baselineRoot, $this->options->baselineUrl),
            $this->options->candidateLabel => $this->installation($this->options->candidateRoot, $this->options->candidateUrl),
        ];
        if ($this->options->order === 'candidate-first') {
            $installations = array_reverse($installations, true);
        }
        $this->assertDatasetParity($installations);
        $representative = $installations[$this->options->baselineLabel]['dataset']['representative'];
        $routes = [
            'feed' => '/',
            'status_thread' => '/status/' . $representative['content_id'],
            'tag_feed' => '/tag/benchmark',
            'author_profile' => '/author/' . $representative['author_id'],
            'search' => '/search?q=performance',
        ];
        $runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $results = [];

        try {
            foreach (['filesystem', 'memcached'] as $mode) {
                foreach ($routes as $routeName => $path) {
                    foreach ($installations as $version => $installation) {
                        $this->progress(sprintf('%s | %s | %s', $version, $mode, $routeName));
                        $prefix = sprintf('tinycat:perf:%s:%s:%s:%s:', $runId, str_replace('.', '-', $version), $mode, $routeName);
                        $this->configureCache($installation['root'], $mode, $prefix);
                        $this->clearFilesystemJsonCache($installation['root']);
                        $url = $installation['url'] . $path;

                        $coldMysql = $this->mysqlStatus($installation['database']);
                        $coldMemcached = $this->memcachedStatus();
                        $cold = $this->http->request($url);
                        $cold['mysql'] = $this->metricDelta($coldMysql, $this->mysqlStatus($installation['database']), 1);
                        $cold['memcached'] = $this->metricDelta($coldMemcached, $this->memcachedStatus(), 1, false);

                        if ($this->options->warmupRequests > 0) {
                            $this->http->run($url, $this->options->warmupRequests, 1);
                        }

                        $sequentialMysql = $this->mysqlStatus($installation['database']);
                        $sequentialMemcached = $this->memcachedStatus();
                        $sequential = $this->http->run($url, $this->options->sequentialRequests, 1);
                        $sequential['mysql'] = $this->metricDelta(
                            $sequentialMysql,
                            $this->mysqlStatus($installation['database']),
                            $this->options->sequentialRequests,
                        );
                        $sequential['memcached'] = $this->metricDelta(
                            $sequentialMemcached,
                            $this->memcachedStatus(),
                            $this->options->sequentialRequests,
                            false,
                        );

                        if ($this->options->warmupRequests > 0 && $this->options->concurrency > 1) {
                            $this->http->run(
                                $url,
                                max($this->options->warmupRequests, $this->options->concurrency * 2),
                                $this->options->concurrency,
                            );
                        }

                        $loadMysql = $this->mysqlStatus($installation['database']);
                        $loadMemcached = $this->memcachedStatus();
                        $load = $this->http->run($url, $this->options->loadRequests, $this->options->concurrency);
                        $load['mysql'] = $this->metricDelta(
                            $loadMysql,
                            $this->mysqlStatus($installation['database']),
                            $this->options->loadRequests,
                        );
                        $load['memcached'] = $this->metricDelta(
                            $loadMemcached,
                            $this->memcachedStatus(),
                            $this->options->loadRequests,
                            false,
                        );
                        $load['php_cgi_working_set_mb_after'] = $this->phpCgiWorkingSetMb();

                        $this->assertSuccessful($version, $mode, $routeName, [$cold, $sequential, $load]);
                        $results[$version][$mode][$routeName] = [
                            'path' => $path,
                            'cold' => $cold,
                            'sequential' => $sequential,
                            'load' => $load,
                        ];
                    }
                }
            }
        } finally {
            $this->restoreConfigs();
        }

        $report = [
            'ok' => true,
            'run_id' => $runId,
            'generated_at' => gmdate(DATE_ATOM),
            'environment' => $this->environment(),
            'labels' => [
                'baseline' => $this->options->baselineLabel,
                'candidate' => $this->options->candidateLabel,
                'order' => $this->options->order,
            ],
            'load' => [
                'sequential_requests' => $this->options->sequentialRequests,
                'load_requests' => $this->options->loadRequests,
                'concurrency' => $this->options->concurrency,
                'warmup_requests' => $this->options->warmupRequests,
            ],
            'installations' => $this->publicInstallations($installations),
            'routes' => $routes,
            'results' => $results,
            'comparison' => $this->comparison($results),
            'cache_impact' => $this->cacheImpact($results),
            'notes' => [
                'Cold cache is reset independently before each route.',
                'MySQL status is global to the local server; queries/request subtracts the status probe itself.',
                'FastCGI memory is the aggregate working set of php-cgi children of the active Apache process after load.',
            ],
        ];
        $this->writeReport($report);

        return $report;
    }

    /** @return array<string, mixed> */
    private function installation(string $root, string $url): array
    {
        $configPath = $root . DIRECTORY_SEPARATOR . 'config.php';
        $config = require $configPath;
        if (!is_array($config) || !is_array($config['database'] ?? null)) {
            throw new RuntimeException('Invalid config: ' . $configPath);
        }
        $database = $this->pdo($config['database']);

        return [
            'root' => $root,
            'url' => $url,
            'config_path' => $configPath,
            'config' => $config,
            'database' => $database,
            'database_name' => (string) $config['database']['name'],
            'dataset' => $this->dataset($database),
        ];
    }

    /** @param array<string, mixed> $database */
    private function pdo(array $database): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($database['host'] ?? 'localhost'),
                (int) ($database['port'] ?? 3306),
                (string) ($database['name'] ?? ''),
                (string) ($database['charset'] ?? 'utf8mb4'),
            ),
            (string) ($database['user'] ?? ''),
            (string) ($database['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    /** @return array<string, mixed> */
    private function dataset(PDO $database): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $this->query($database, 'SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        $content = $this->query($database,
            'SELECT content_id, COUNT(*) AS comment_count
             FROM content_comments GROUP BY content_id ORDER BY comment_count DESC, content_id ASC LIMIT 1'
        )->fetch();
        $author = $this->query($database,
            'SELECT author_id, COUNT(*) AS post_count
             FROM content GROUP BY author_id ORDER BY post_count DESC, author_id ASC LIMIT 1'
        )->fetch();
        if (!is_array($content) || !is_array($author)) {
            throw new RuntimeException('Imported dataset is empty.');
        }

        return [
            'counts' => $counts,
            'representative' => [
                'content_id' => (int) $content['content_id'],
                'content_comments' => (int) $content['comment_count'],
                'author_id' => (int) $author['author_id'],
                'author_posts' => (int) $author['post_count'],
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $installations */
    private function assertDatasetParity(array $installations): void
    {
        $baseline = $installations[$this->options->baselineLabel]['dataset'];
        $candidate = $installations[$this->options->candidateLabel]['dataset'];
        if ($baseline !== $candidate) {
            throw new RuntimeException('Datasets differ. Import the same profile and seed into both installations first.');
        }
    }

    private function configureCache(string $root, string $mode, string $prefix): void
    {
        $path = $root . DIRECTORY_SEPARATOR . 'config.php';
        if (!isset($this->originalConfigs[$path])) {
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new RuntimeException('Cannot read config: ' . $path);
            }
            $this->originalConfigs[$path] = $contents;
        }
        $config = require $path;
        $config['cache'] = $mode === 'memcached'
            ? [
                'driver' => 'memcached',
                'memcached' => [
                    'servers' => [['host' => '127.0.0.1', 'port' => 11211]],
                    'prefix' => $prefix,
                    'timeout_ms' => 100,
                ],
            ]
            : ['driver' => 'filesystem'];
        $this->atomicWrite($path, "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    }

    private function restoreConfigs(): void
    {
        foreach ($this->originalConfigs as $path => $contents) {
            try {
                $this->atomicWrite($path, $contents);
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Unable to restore ' . $path . ': ' . $exception->getMessage() . PHP_EOL);
            }
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        if (is_file($path)) {
            if (file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write file: ' . $path);
            }

            return;
        }
        $temporary = $path . '.benchmark-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write config atomically: ' . $path);
        }
    }

    private function clearFilesystemJsonCache(string $root): void
    {
        $cache = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        $files = glob($cache . DIRECTORY_SEPARATOR . '*.json') ?: [];
        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Cannot clear cache file: ' . $file);
            }
        }
    }

    /** @return array<string, int> */
    private function mysqlStatus(PDO $database): array
    {
        $quoted = implode(', ', array_map(static fn (string $name): string => $database->quote($name), self::MYSQL_STATUS));
        $statement = $this->query($database, 'SHOW GLOBAL STATUS WHERE Variable_name IN (' . $quoted . ')');
        $status = [];
        foreach ($statement->fetchAll() as $row) {
            $status[(string) $row['Variable_name']] = (int) $row['Value'];
        }

        return $status;
    }

    /** @return array<string, int> */
    private function memcachedStatus(): array
    {
        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('The memcached PHP extension is required for the comparison.');
        }
        $client = new Memcached();
        $client->addServer('127.0.0.1', 11211);
        $servers = $client->getStats();
        if ($servers === []) {
            throw new RuntimeException('Memcached is not available at 127.0.0.1:11211.');
        }
        $status = [];
        foreach ($servers as $server) {
            foreach (['get_hits', 'get_misses', 'cmd_get', 'cmd_set', 'bytes_read', 'bytes_written'] as $metric) {
                $status[$metric] = ($status[$metric] ?? 0) + (int) ($server[$metric] ?? 0);
            }
        }

        return $status;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return array<string, int|float>
     */
    private function metricDelta(array $before, array $after, int $requests, bool $mysql = true): array
    {
        $delta = [];
        foreach ($after as $metric => $value) {
            $delta[$metric] = max(0, $value - ($before[$metric] ?? 0));
        }
        if ($mysql) {
            $questions = max(0, (int) ($delta['Questions'] ?? 0) - 1);
            $delta['questions_per_request'] = round($questions / max(1, $requests), 3);
        }

        return $delta;
    }

    /** @param list<array<string, mixed>> $runs */
    private function assertSuccessful(string $version, string $mode, string $route, array $runs): void
    {
        foreach ($runs as $run) {
            if ((int) $run['failures'] > 0 || (int) $run['fatal_responses'] > 0) {
                throw new RuntimeException(sprintf(
                    'HTTP failure in %s / %s / %s: %s',
                    $version,
                    $mode,
                    $route,
                    json_encode([
                        'requests' => $run['requests'] ?? null,
                        'failures' => $run['failures'] ?? null,
                        'fatal_responses' => $run['fatal_responses'] ?? null,
                        'status_codes' => $run['status_codes'] ?? null,
                        'errors' => $run['errors'] ?? null,
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ));
            }
        }
    }

    private function phpCgiWorkingSetMb(): ?float
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }
        $script = <<<'POWERSHELL'
$ProgressPreference = 'SilentlyContinue'
$apacheStart = (Get-Process httpd -ErrorAction SilentlyContinue | Sort-Object StartTime -Descending | Select-Object -First 1).StartTime
$workers = if ($null -eq $apacheStart) { @() } else { @(Get-Process php-cgi -ErrorAction SilentlyContinue | Where-Object { $_.StartTime -ge $apacheStart }) }
$bytes = ($workers | Measure-Object -Property WorkingSet64 -Sum).Sum
if ($null -eq $bytes) { $bytes = 0 }
[Console]::Write($bytes)
POWERSHELL;
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $output = shell_exec('powershell.exe -NoProfile -EncodedCommand ' . escapeshellarg($encoded));
        if (!is_string($output) || !is_numeric(trim($output))) {
            return null;
        }

        return round((float) trim($output) / 1048576, 3);
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        return [
            'php' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => php_uname(),
            'curl' => curl_version()['version'] ?? null,
            'memcached_extension' => phpversion('memcached') ?: null,
            'opcache_extension_loaded' => extension_loaded('Zend OPcache'),
            'opcache_configured' => filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL),
            'mysql_server' => $this->serverVersion(),
        ];
    }

    private function serverVersion(): string
    {
        $config = require $this->options->baselineRoot . DIRECTORY_SEPARATOR . 'config.php';

        return (string) $this->pdo($config['database'])->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * @param array<string, array<string, mixed>> $installations
     * @return array<string, mixed>
     */
    private function publicInstallations(array $installations): array
    {
        $public = [];
        foreach ($installations as $version => $installation) {
            $public[$version] = [
                'root' => $installation['root'],
                'url' => $installation['url'],
                'database' => $installation['database_name'],
                'dataset' => $installation['dataset'],
            ];
        }

        return $public;
    }

    /**
     * @param array<string, mixed> $results
     * @return array<string, mixed>
     */
    private function comparison(array $results): array
    {
        $comparison = [];
        foreach (['filesystem', 'memcached'] as $mode) {
            foreach ($results[$this->options->baselineLabel][$mode] as $route => $baseline) {
                $candidate = $results[$this->options->candidateLabel][$mode][$route];
                foreach (['cold', 'sequential', 'load'] as $kind) {
                    $baselineP95 = (float) $baseline[$kind]['latency_ms']['p95'];
                    $candidateP95 = (float) $candidate[$kind]['latency_ms']['p95'];
                    $baselineRps = (float) $baseline[$kind]['requests_per_second'];
                    $candidateRps = (float) $candidate[$kind]['requests_per_second'];
                    $comparison[$mode][$route][$kind] = [
                        'p95_latency_change_percent' => $this->percentChange($baselineP95, $candidateP95),
                        'throughput_change_percent' => $this->percentChange($baselineRps, $candidateRps),
                    ];
                }
            }
        }

        return $comparison;
    }

    private function percentChange(float $baseline, float $candidate): ?float
    {
        return $baseline === 0.0 ? null : round((($candidate - $baseline) / $baseline) * 100, 2);
    }

    /**
     * @param array<string, mixed> $results
     * @return array<string, mixed>
     */
    private function cacheImpact(array $results): array
    {
        $impact = [];
        foreach ([$this->options->baselineLabel, $this->options->candidateLabel] as $version) {
            foreach ($results[$version]['filesystem'] as $route => $filesystem) {
                $memcached = $results[$version]['memcached'][$route];
                foreach (['cold', 'sequential', 'load'] as $kind) {
                    $impact[$version][$route][$kind] = [
                        'p95_latency_change_percent' => $this->percentChange(
                            (float) $filesystem[$kind]['latency_ms']['p95'],
                            (float) $memcached[$kind]['latency_ms']['p95'],
                        ),
                        'throughput_change_percent' => $this->percentChange(
                            (float) $filesystem[$kind]['requests_per_second'],
                            (float) $memcached[$kind]['requests_per_second'],
                        ),
                    ];
                }
            }
        }

        return $impact;
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): void
    {
        $directory = dirname($this->options->outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create report directory: ' . $directory);
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $this->atomicWrite($this->options->outputPath, $json);
        $markdownPath = preg_replace('/\.json$/i', '.md', $this->options->outputPath) ?: $this->options->outputPath . '.md';
        $this->atomicWrite($markdownPath, $this->markdown($report));
    }

    /** @param array<string, mixed> $report */
    private function markdown(array $report): string
    {
        $lines = [
            '# TinyCat ' . $this->options->baselineLabel . ' vs ' . $this->options->candidateLabel . ' performance',
            '',
            'Generated: ' . $report['generated_at'],
            '',
            '| Cache | Route | Version | Cold ms | Seq p50/p95 ms | Load p95 ms | Load req/s | SQL q/request | Failures |',
            '|---|---|---:|---:|---:|---:|---:|---:|---:|',
        ];
        foreach (['filesystem', 'memcached'] as $mode) {
            foreach ($report['routes'] as $route => $_path) {
                foreach ([$this->options->baselineLabel, $this->options->candidateLabel] as $version) {
                    $row = $report['results'][$version][$mode][$route];
                    $lines[] = sprintf(
                        '| %s | %s | %s | %.2f | %.2f / %.2f | %.2f | %.2f | %.2f | %d |',
                        $mode,
                        $route,
                        $version,
                        $row['cold']['latency_ms']['p50'],
                        $row['sequential']['latency_ms']['p50'],
                        $row['sequential']['latency_ms']['p95'],
                        $row['load']['latency_ms']['p95'],
                        $row['load']['requests_per_second'],
                        $row['load']['mysql']['questions_per_request'],
                        $row['load']['failures'],
                    );
                }
            }
        }
        $lines[] = '';
        $lines[] = 'Negative latency change favors ' . $this->options->candidateLabel
            . '; positive throughput change favors ' . $this->options->candidateLabel . '.';
        $lines[] = '';
        $lines[] = '## Load comparison';
        $lines[] = '';
        $lines[] = '| Cache | Route | Candidate p95 change | Candidate throughput change |';
        $lines[] = '|---|---|---:|---:|';
        foreach (['filesystem', 'memcached'] as $mode) {
            foreach ($report['routes'] as $route => $_path) {
                $change = $report['comparison'][$mode][$route]['load'];
                $lines[] = sprintf(
                    '| %s | %s | %+.2f%% | %+.2f%% |',
                    $mode,
                    $route,
                    $change['p95_latency_change_percent'],
                    $change['throughput_change_percent'],
                );
            }
        }
        $lines[] = '';
        $lines[] = '## Memcached impact';
        $lines[] = '';
        $lines[] = '| Version | Route | p95 change vs filesystem | Throughput change |';
        $lines[] = '|---:|---|---:|---:|';
        foreach ([$this->options->baselineLabel, $this->options->candidateLabel] as $version) {
            foreach ($report['routes'] as $route => $_path) {
                $change = $report['cache_impact'][$version][$route]['load'];
                $lines[] = sprintf(
                    '| %s | %s | %+.2f%% | %+.2f%% |',
                    $version,
                    $route,
                    $change['p95_latency_change_percent'],
                    $change['throughput_change_percent'],
                );
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function progress(string $message): void
    {
        fwrite(STDOUT, '[' . gmdate('H:i:s') . '] ' . $message . PHP_EOL);
    }

    private function query(PDO $database, string $sql): PDOStatement
    {
        $statement = $database->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Database query failed.');
        }

        return $statement;
    }
}
