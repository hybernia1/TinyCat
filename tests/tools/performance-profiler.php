<?php
declare(strict_types=1);

require_once __DIR__ . '/../../tools/support/workspace.php';

require __DIR__ . '/../../tools/performance/lib/ProfileDatabase.php';
require __DIR__ . '/../../tools/performance/lib/CallProfile.php';

foreach (['../storage', '/absolute', 'C:/outside', 'tests//tmp', 'tests/../tmp', ''] as $invalid) {
    try {
        \TinyCat\Tools\Workspace::directory($invalid);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Development workspace accepted an unsafe category.');
}
$workspace = \TinyCat\Tools\Workspace::directory('tests/tmp');
if (str_replace('\\', '/', $workspace) !== str_replace('\\', '/', dirname(__DIR__, 2)) . '/.work/tests/tmp') {
    throw new RuntimeException('Development outputs must remain separate from runtime storage.');
}
echo "PASS development workspace rejects traversal and isolates outputs\n";

$database = new \TinyCat\Tools\Performance\ProfileDatabase('sqlite::memory:', '', '');
$database->exec('CREATE TABLE sample (value INTEGER)');
$statement = $database->prepare('INSERT INTO sample VALUES (?)');
if (count($database->queries) !== 1) { throw new RuntimeException('Preparing a statement must not count as executing it.'); }
$statement->execute([1]);
$statement->execute([2]);
$count = $database->query('SELECT COUNT(*) FROM sample')->fetchColumn();
if ((int) $count !== 2 || count($database->queries) !== 4) { throw new RuntimeException('Repeated execute, query and exec must each be counted exactly once.'); }
try { $database->query('SELECT * FROM missing'); } catch (PDOException) { }
if (count($database->queries) !== 5) { throw new RuntimeException('Failed SQL must remain visible in measurements.'); }
foreach ($database->queries as $query) {
    if ($query['ms'] < 0 || $query['sql'] === '') { throw new RuntimeException('Invalid SQL measurement.'); }
}
echo "PASS execution profiler counts reused statements, direct queries and failures\n";

\TinyCat\Tools\Performance\CallProfile::enter('outer');
\TinyCat\Tools\Performance\CallProfile::enter('inner');
usleep(1000);
\TinyCat\Tools\Performance\CallProfile::leave();
\TinyCat\Tools\Performance\CallProfile::leave();
$profile = \TinyCat\Tools\Performance\CallProfile::report();
if ($profile['outer']['calls'] !== 1 || $profile['inner']['calls'] !== 1
    || abs($profile['outer']['inclusive_ms'] - $profile['outer']['self_ms'] - $profile['inner']['inclusive_ms']) > 0.003
) { throw new RuntimeException('Call profiling must separate nested and self time without double-counting.'); }
echo "PASS nested call profiling separates inclusive and self time\n";

$root = dirname(__DIR__, 2);
$temporary = \TinyCat\Tools\Workspace::directory('performance') . '/profiler-empty-' . bin2hex(random_bytes(6));
mkdir($temporary . '/source/App/Core/Runtime', 0700, true);
file_put_contents($temporary . '/source/App/Core/Runtime/autoload.php', "<?php\n");
copy($root . '/tests/fixtures/performance/profiler-empty.php', $temporary . '/source/App/Fixture.php');
try {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/performance/instrument-performance.php')
        . ' --source=' . escapeshellarg($temporary . '/source') . ' --directory=' . escapeshellarg($temporary . '/instrumented');
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) { throw new RuntimeException('Instrumenting empty bodies failed: ' . implode(' ', $output)); }
    require $temporary . '/instrumented/App/Fixture.php';
    (new ProfilerEmptyFixture())->idle();
    profilerEmptyFunction();
    $profile = \TinyCat\Tools\Performance\CallProfile::report();
    foreach (['ProfilerEmptyFixture::__construct', 'ProfilerEmptyFixture::idle', 'profilerEmptyFunction'] as $name) {
        if (($profile[$name]['calls'] ?? 0) !== 1) { throw new RuntimeException('Empty body was not profiled: ' . $name); }
    }
} finally {
    foreach (['instrumented', 'source'] as $directory) {
        foreach (['Fixture.php', 'Core/Runtime/autoload.php'] as $file) {
            $path = $temporary . '/' . $directory . '/App/' . $file;
            if (is_file($path)) { unlink($path); }
        }
        foreach (['App/Core/Runtime', 'App/Core', 'App'] as $relative) {
            if (is_dir($temporary . '/' . $directory . '/' . $relative)) { rmdir($temporary . '/' . $directory . '/' . $relative); }
        }
        if (is_dir($temporary . '/' . $directory)) { rmdir($temporary . '/' . $directory); }
    }
    rmdir($temporary);
}
echo "PASS instrumentation of empty functions, methods and constructors\n";
