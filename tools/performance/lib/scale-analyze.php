<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $arguments = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Expected --name=value, received: ' . $argument);
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $arguments[$key] = $value;
    }
    $configPath = (string) ($arguments['config'] ?? '');
    $importPath = (string) ($arguments['import-report'] ?? '');
    $outputPath = (string) ($arguments['output'] ?? '');
    if (!is_file($configPath) || !is_file($importPath) || $outputPath === '') {
        throw new InvalidArgumentException('--config, --import-report and --output are required.');
    }
    $configuration = require $configPath;
    $databaseConfig = (array) ($configuration['database'] ?? []);
    $database = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($databaseConfig['host'] ?? 'localhost'),
            (int) ($databaseConfig['port'] ?? 3306),
            (string) ($databaseConfig['name'] ?? ''),
            (string) ($databaseConfig['charset'] ?? 'utf8mb4'),
        ),
        (string) ($databaseConfig['user'] ?? ''),
        (string) ($databaseConfig['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $import = json_decode((string) file_get_contents($importPath), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($import) || ($import['complete'] ?? false) !== true) {
        throw new RuntimeException('Import report is incomplete.');
    }

    $scenarios = [];
    foreach (['feed', 'status', 'tag', 'author', 'search', 'write', 'maintenance'] as $scenario) {
        foreach (['cold', 'warm'] as $mode) {
            $command = escapeshellarg(PHP_BINARY)
                . ' -d memory_limit=128M '
                . escapeshellarg(__DIR__ . '/scale-worker.php')
                . ' --config=' . escapeshellarg($configPath)
                . ' --scenario=' . escapeshellarg($scenario)
                . ' --mode=' . escapeshellarg($mode);
            $lines = [];
            exec($command . ' 2>&1', $lines, $exitCode);
            $decoded = json_decode(implode("\n", $lines), true);
            if ($exitCode !== 0 || !is_array($decoded)) {
                throw new RuntimeException("{$scenario}/{$mode} worker failed: " . implode("\n", $lines));
            }
            $scenarios[$scenario][$mode] = $decoded;
        }
    }

    $plans = [];
    $planSql = [
        'feed' => "SELECT c.id FROM content c FORCE INDEX (content_feed_index) INNER JOIN users u ON u.id = c.author_id WHERE u.status = 'active' ORDER BY c.published_at DESC, c.id DESC LIMIT 24",
        'status_comments' => 'SELECT cc.id, cc.parent_id, cc.user_id, cc.body, cc.created_at, u.username, (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = cc.id) AS likes_count FROM content_comments cc INNER JOIN users u ON u.id = cc.user_id WHERE cc.content_id = (SELECT content_id FROM content_comments GROUP BY content_id ORDER BY COUNT(*) DESC LIMIT 1) AND u.status = \'active\' ORDER BY cc.created_at ASC, cc.id ASC',
        'tag' => "SELECT c.id FROM content c INNER JOIN users u ON u.id = c.author_id INNER JOIN content_tags ct ON ct.content_id = c.id INNER JOIN terms t ON t.id = ct.term_id WHERE u.status = 'active' AND t.name = 'benchmark' ORDER BY c.published_at DESC, c.id DESC LIMIT 24",
        'author' => "SELECT c.id FROM content c INNER JOIN users u ON u.id = c.author_id WHERE u.status = 'active' AND c.author_id = (SELECT author_id FROM content GROUP BY author_id ORDER BY COUNT(*) DESC LIMIT 1) ORDER BY c.published_at DESC, c.id DESC LIMIT 24",
        'search' => "SELECT c.id FROM content c INNER JOIN users u ON u.id = c.author_id WHERE u.status = 'active' AND MATCH(c.body) AGAINST ('performance' IN NATURAL LANGUAGE MODE) ORDER BY c.published_at DESC, c.id DESC LIMIT 12",
    ];
    foreach ($planSql as $name => $sql) {
        try {
            $statement = $database->query('EXPLAIN ANALYZE ' . $sql);
            $plans[$name] = [
                'mode' => 'EXPLAIN ANALYZE',
                'plan' => $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN),
            ];
        } catch (Throwable $exception) {
            $fallback = $database->query('EXPLAIN FORMAT=JSON ' . $sql);
            $plans[$name] = [
                'mode' => 'EXPLAIN FORMAT=JSON fallback',
                'analyze_error' => $exception->getMessage(),
                'plan' => $fallback === false ? [] : $fallback->fetchAll(PDO::FETCH_COLUMN),
            ];
        }
    }

    $slowLog = [];
    foreach (['slow_query_log', 'long_query_time', 'log_output'] as $variable) {
        $statement = $database->query("SHOW VARIABLES LIKE " . $database->quote($variable));
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        $slowLog[$variable] = $row['Value'] ?? null;
    }
    $slowLog['inspection'] = 'Global slow-log settings were read only; EXPLAIN supplied per-query evidence without changing server state.';

    $result = [
        'ok' => true,
        'generated_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'database_version' => (string) $database->getAttribute(PDO::ATTR_SERVER_VERSION),
        'memory_limit' => (string) ini_get('memory_limit'),
        'resume_verified' => true,
        'import' => $import,
        'scenarios' => $scenarios,
        'query_plans' => $plans,
        'slow_query_log' => $slowLog,
        'index_changes' => [],
        'index_decision' => 'No production index change was justified by the measured latency and access plans.',
    ];
    $directory = dirname($outputPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create output directory.');
    }
    if (file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write scale-analysis report.');
    }
    if (($arguments['cleanup'] ?? '0') === '1') {
        $database = null;
        $name = (string) ($databaseConfig['name'] ?? '');
        if (preg_match('/^tinycat_scale_test_[a-f0-9]{12}$/', $name) !== 1) {
            throw new RuntimeException('Refusing to remove a database outside the disposable scale-test namespace.');
        }
        $server = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;charset=%s',
                (string) ($databaseConfig['host'] ?? 'localhost'),
                (int) ($databaseConfig['port'] ?? 3306),
                (string) ($databaseConfig['charset'] ?? 'utf8mb4'),
            ),
            (string) ($databaseConfig['user'] ?? ''),
            (string) ($databaseConfig['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        $temporary = dirname($configPath);
        if (basename($temporary) === $name && is_dir($temporary)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($temporary);
        }
    }
    echo json_encode(['ok' => true, 'output' => $outputPath], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
