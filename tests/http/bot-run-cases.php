<?php
declare(strict_types=1);

$botRunsDb = new PDO('sqlite:' . $databasePath);
$seedRunSource = $botRunsDb->prepare('INSERT INTO bot_sources (id, bot_user_id, name, feed_url, feed_hash, post_template) VALUES (?, ?, ?, ?, ?, ?)');
$seedRunSource->execute([95001, 3, 'History source', 'https://example.org/history', 'history-fixture', '{{title}}']);
$seedRunSource->execute([95002, $editableBotId, 'Other bot source', 'https://example.org/other-history', 'other-history-fixture', '{{title}}']);
$seedRun = $botRunsDb->prepare('INSERT INTO bot_source_runs (id, source_id, status, started_at, items_seen, error) VALUES (?, ?, ?, ?, ?, ?)');
foreach (range(1, 31) as $number) {
    $seedRun->execute([96000 + $number, 95001, $number % 2 ? 'error' : 'completed', '2026-10-30 12:00:00', $number, 'History note ' . $number]);
}
$seedRun->execute([96999, 95002, 'error', '2026-10-30 12:00:00', 999, 'History note foreign']);
[$status] = $request('GET', '/api/bots/accounts/3/runs');
$assert($status === 401, 'Bot run history is not public.');
[$status] = $request('GET', '/api/bots/accounts/3/runs', null, 'user');
$assert($status === 403, 'Only administrators can search bot run history.');
[$status] = $request('GET', '/api/bots/accounts/2/runs', null, 'admin');
$assert($status === 404, 'Run history cannot target a regular user.');
[$status, $runPage] = $request('GET', '/api/bots/accounts/3/runs?per_page=10', null, 'admin');
$assert($status === 200 && count($runPage['data']['runs']) === 10 && $runPage['meta']['pagination']['total'] === 31, 'Run history paginates all records and excludes other bots.');
$assert($runPage['data']['runs'][0]['id'] === 96031 && $runPage['data']['list_target'] === '#bot-detail-runs-list' && $runPage['data']['history_url'] === false, 'Run history has stable ordering and an independent modal target.');
$assert(!isset($runPage['data']['sources'][95002]) && $runPage['data']['sorting']['started_at']['history'] === false && $runPage['data']['navigation']['next']['history_url'] === false, 'Choices, sorting and pagination stay scoped to the modal and bot.');
[$status, $nextRunPage] = $request('GET', '/api/bots/accounts/3/runs?per_page=10&page=2', null, 'admin');
$assert($status === 200 && $nextRunPage['data']['runs'][0]['id'] === 96021, 'The next history page continues with no duplicate records.');
[$status, $filteredRuns] = $request('GET', '/api/bots/accounts/3/runs?status=error&source=95001&sort=items_seen&direction=asc&per_page=10', null, 'admin');
$assert($status === 200 && $filteredRuns['meta']['pagination']['total'] === 16 && $filteredRuns['data']['runs'][0]['items_seen'] === 1, 'Source and result filters combine with numeric sorting.');
[$status, $searchedRuns] = $request('GET', '/api/bots/accounts/3/runs?q=History%20note%2031', null, 'admin');
$assert($status === 200 && $searchedRuns['meta']['pagination']['total'] === 1 && $searchedRuns['data']['runs'][0]['id'] === 96031, 'History search includes diagnostic notes.');
[$status, $foreignRuns] = $request('GET', '/api/bots/accounts/3/runs?source=95002', null, 'admin');
$assert($status === 200 && $foreignRuns['meta']['pagination']['total'] === 31 && $foreignRuns['data']['filters']['source'] === '' && count(array_filter($foreignRuns['data']['runs'], static fn(array $run): bool => $run['source_id'] !== 95001)) === 0, 'An unavailable source is normalized while results remain scoped to the bot.');
[$status, $runMetadata] = $request('GET', '/api/bots/accounts/3', null, 'admin');
$assert($status === 200 && $runMetadata['data']['item']['run_count'] === 31, 'The workspace statistic counts all runs, beyond the old thirty-record limit.');
$botRunsDb->exec('DELETE FROM bot_source_runs WHERE id BETWEEN 96001 AND 96999');
$botRunsDb->exec('DELETE FROM bot_sources WHERE id IN (95001, 95002)');
$seedRunSource = $seedRun = $botRunsDb = null;
