<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @return array<string, string> */
function scaleWorkerArguments(array $arguments): array
{
    $parsed = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Expected --name=value, received: ' . $argument);
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $parsed[$key] = $value;
    }

    return $parsed;
}

/** @return int */
function scaleWorkerQuestions(PDO $database): int
{
    $statement = $database->query("SHOW SESSION STATUS LIKE 'Questions'");
    $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['Value'] ?? 0);
}

/** @param list<float> $values */
function scaleWorkerPercentile(array $values, float $percentile): float
{
    sort($values, SORT_NUMERIC);
    if ($values === []) {
        return 0.0;
    }
    $index = (int) ceil((count($values) - 1) * $percentile);

    return round($values[$index], 3);
}

/** @param callable(): void $exercise */
function scaleWorkerMeasure(PDO $database, callable $exercise, int $warmup, int $iterations): array
{
    for ($index = 0; $index < $warmup; $index++) {
        $exercise();
    }

    $before = scaleWorkerQuestions($database);
    $durations = [];
    for ($index = 0; $index < $iterations; $index++) {
        $started = hrtime(true);
        $exercise();
        $durations[] = (hrtime(true) - $started) / 1_000_000;
    }
    $after = scaleWorkerQuestions($database);

    return [
        'iterations' => $iterations,
        'warmup_iterations' => $warmup,
        'p50_ms' => scaleWorkerPercentile($durations, 0.50),
        'p95_ms' => scaleWorkerPercentile($durations, 0.95),
        'max_ms' => round(max($durations), 3),
        'questions_total' => max(0, $after - $before - 1),
        'questions_per_iteration' => round(max(0, $after - $before - 1) / $iterations, 2),
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ];
}

try {
    $arguments = scaleWorkerArguments($argv);
    $configPath = (string) ($arguments['config'] ?? '');
    $scenario = (string) ($arguments['scenario'] ?? '');
    $mode = (string) ($arguments['mode'] ?? 'cold');
    if (!is_file($configPath) || !in_array($scenario, ['feed', 'status', 'tag', 'author', 'search', 'write', 'maintenance'], true)) {
        throw new InvalidArgumentException('A valid --config and --scenario are required.');
    }
    if (!in_array($mode, ['cold', 'warm'], true)) {
        throw new InvalidArgumentException('Mode must be cold or warm.');
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

    $root = dirname(__DIR__, 2);
    define('TINYCAT', true);
    foreach ([
        'autoload.php', 'Core.php', 'Captcha.php', 'Cache.php', 'Minifier.php', 'Avatar.php',
        'StatusImage.php', 'SiteIdentity.php', 'StatusLinks.php', 'LinkMetadata.php',
        'Notifications.php', 'UserRoles.php', 'functions.php',
    ] as $file) {
        require_once $root . '/App/' . $file;
    }

    (new ReflectionProperty(Core::class, 'booted'))->setValue(null, true);
    (new ReflectionProperty(Core::class, 'config'))->setValue(null, [
        'app' => ['url' => 'http://localhost'],
        'database' => ['driver' => 'mysql'],
        'install' => ['complete' => true, 'locale' => 'en'],
        'cache' => ['driver' => 'filesystem', 'prefix' => 'scale_worker_'],
        'security' => ['captcha' => ['enabled' => false]],
    ]);
    $settings = new ReflectionProperty(Core::class, 'settings');
    $settings->setValue(null, []);
    Core::setDb($database);
    $settings->setValue(null, []);

    $_GET = $_POST = $_COOKIE = $_FILES = [];
    $_SERVER = [
        'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost',
        'SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '/index.php',
    ];

    $statusId = (int) $database->query(
        'SELECT c.id FROM content c LEFT JOIN content_comments cc ON cc.content_id = c.id GROUP BY c.id ORDER BY COUNT(cc.id) DESC, c.id DESC LIMIT 1'
    )->fetchColumn();
    $authorId = (int) $database->query(
        'SELECT author_id FROM content GROUP BY author_id ORDER BY COUNT(*) DESC, author_id ASC LIMIT 1'
    )->fetchColumn();
    $writeSequence = 0;

    $exercise = match ($scenario) {
        'feed' => static function (): void {
            public_status_items_cursor(24);
        },
        'status' => static function () use ($statusId): void {
            $item = public_status_item($statusId);
            if ($item === null) {
                throw new RuntimeException('Representative status was not found.');
            }
            status_preload_feed([$item]);
            status_comments($statusId);
        },
        'tag' => static function (): void {
            public_status_items_by_tag('benchmark', 24);
        },
        'author' => static function () use ($authorId): void {
            public_author_find($authorId);
            public_status_items_by_author_cursor($authorId, 24);
            author_follow_counts($authorId);
            author_activity_stats($authorId);
            author_following_profiles($authorId, 10);
        },
        'search' => static function (): void {
            public_search_results('performance', 12);
        },
        'write' => static function () use ($authorId, &$writeSequence): void {
            $writeSequence++;
            $created = date('Y-m-d H:i:s');
            $contentId = (int) insert('content', [
                'body' => 'Scale benchmark write ' . $writeSequence . ' #benchmark #performance',
                'author_id' => $authorId,
                'published_at' => $created,
            ]);
            status_sync_tags($contentId, ['benchmark', 'performance']);
            insert('content_comments', [
                'content_id' => $contentId,
                'parent_id' => null,
                'user_id' => $authorId,
                'body' => 'Scale benchmark write comment.',
                'created_at' => $created,
            ]);
            status_delete_content($contentId);
        },
        'maintenance' => static function (): void {
            cleanup_task_run('orphan_terms', 500);
        },
    };

    $iterations = $mode === 'warm' ? 10 : 1;
    $warmup = $mode === 'warm' ? 2 : 0;
    if ($scenario === 'maintenance') {
        $insert = $database->prepare('INSERT IGNORE INTO terms (name) VALUES (?)');
        $prefix = bin2hex(random_bytes(6));
        $database->beginTransaction();
        for ($index = 0; $index < ($iterations + $warmup) * 500; $index++) {
            $insert->execute(['scale_orphan_' . $prefix . '_' . $index]);
        }
        $database->commit();
    }
    $result = scaleWorkerMeasure($database, $exercise, $warmup, $iterations);
    echo json_encode([
        'ok' => true,
        'scenario' => $scenario,
        'mode' => $mode,
        'memory_limit' => (string) ini_get('memory_limit'),
        ...$result,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
