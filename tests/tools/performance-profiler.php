<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/performance/ProfileDatabase.php';
require dirname(__DIR__, 2) . '/tools/performance/CallProfile.php';

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
