<?php
declare(strict_types=1);

$systemDb = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
[$status, $installation] = $request('GET', '/api/installation');
$assert($status === 404 && $installation['code'] === 'not_found' && $installation['data'] === null, 'Installed sites do not expose setup status.');
[$status] = $request('POST', '/api/installation/steps/language', ['locale' => 'en']);
$assert($status === 404, 'Installed setup endpoints are hidden even without a CSRF token.');
[$status, $locked] = $request('POST', '/api/installation/steps/language', ['locale' => 'en'], '', $csrf);
$assert($status === 404 && $locked['code'] === 'not_found', 'Installed sites hide setup writes even with a valid CSRF token.');
$systemDb->exec("DELETE FROM settings WHERE setting_key = 'cron.token'");
[$status, $unconfigured] = $request('GET', '/api/scheduler/health');
$assert($status === 503 && $unconfigured['code'] === 'scheduler_not_configured', 'Scheduler authorization requires an explicitly configured token.');
[$status] = $exportRequest('/admin/cron', 'GET', ['X-Test-Role' => 'admin']);
$assert($status === 200 && (int) $systemDb->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'cron.token'")->fetchColumn() === 0, 'Viewing scheduler settings does not create a token over GET.');
[$status, $createdToken] = $request('POST', '/api/scheduler/tokens', [], 'admin', $csrf);
$token = $createdToken['data']['token'];
$assert($status === 201 && strlen($token) === 64, 'Scheduler credentials are created explicitly through the admin API.');
foreach ([[], ['Authorization' => 'Bearer invalid'], ['X-TinyCat-Cron' => $token]] as $headers) {
    [$status] = $request('GET', '/api/scheduler/health', null, 'admin', $headers);
    $assert($status === 401, 'A session or a nonstandard token header cannot replace Bearer authorization.');
}
[$status] = $request('GET', '/api/scheduler/health?bearer=' . $token);
$assert($status === 401, 'Query-string credentials are not accepted.');
$bearer = ['Authorization' => 'Bearer ' . $token];
$before = (int) $systemDb->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn();
[$status, $health] = $request('GET', '/api/scheduler/health', null, '', $bearer);
$assert($status === 200 && $health['code'] === 'scheduler_healthy' && $health['data']['available_tasks'] === ['feeds', 'cleanup', 'site-health'], 'Authenticated scheduler health uses the standard resource envelope.');
$assert((int) $systemDb->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn() === $before, 'Health checks never execute tasks.');
[$status, $invalid] = $request('POST', '/api/scheduler/executions', ['task' => 'unknown'], '', $bearer);
$assert($status === 400, 'Unknown scheduler tasks fail before execution.');
[$status] = $request('POST', '/api/scheduler/executions', ['task' => 'all', 'options' => ['unknown' => 1]], '', $bearer);
$assert($status === 400 && (int) $systemDb->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn() === $before, 'All task options are validated before starting any selected task.');
[$status] = $request('POST', '/api/scheduler/executions', ['options' => 'invalid'], '', $bearer);
$assert($status === 422, 'Malformed task options use standard validation errors.');
[$status, $execution] = $request('POST', '/api/scheduler/executions', ['task' => 'all'], '', $bearer + ['X-Test-Scheduler-Lock' => '0']);
$assert($status === 200 && $execution['code'] === 'scheduler_run_finished' && $execution['data']['tasks']['feeds']['status'] === 'already_running' && $execution['data']['tasks']['cleanup']['status'] === 'already_running', 'Bearer execution shares task locks and requires no browser CSRF token.');
[$status, $retired] = $request('POST', '/scheduled-tasks.php', [], '', $bearer);
$assert($status === 410 && $retired['code'] === 'endpoint_retired' && $retired['error']['details']['endpoint'] === '/api/scheduler/executions', 'The legacy HTTP script retires through the shared front controller without running work.');
[$status] = $request('GET', '/api/scheduler/executions', null, '', $bearer);
$assert($status === 405, 'Scheduler runs cannot mutate state over GET.');
$basic = ['Authorization' => 'Basic ' . base64_encode('scheduler:' . $token), 'X-Forwarded-Proto' => 'https'];
[$status] = $request('GET', '/api/scheduler/triggers/cleanup', null, '', ['Authorization' => $basic['Authorization']]);
$assert($status === 426, 'Web-cron credentials require HTTPS.');
foreach ([[], ['Authorization' => 'Basic invalid'], ['Authorization' => 'Basic ' . base64_encode('scheduler:wrong')], $bearer] as $headers) {
    [$status] = $request('GET', '/api/scheduler/triggers/cleanup?bearer=' . $token, null, '', $headers + ['X-Forwarded-Proto' => 'https']);
    $assert($status === 401, 'Web-cron requires valid Basic credentials, independently of query tokens and Bearer credentials.');
}
$before = (int) $systemDb->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn();
[$status, $body, $headers] = $exportRequest('/api/scheduler/triggers/cleanup', 'HEAD', $basic);
$assert($status === 200 && $body === '' && str_contains($headers['cache-control'], 'no-store'), 'Hosting HEAD probes authenticate without a response body or cacheable result.');
$assert((int) $systemDb->query('SELECT COUNT(*) FROM scheduler_runs')->fetchColumn() === $before, 'Hosting HEAD probes never execute tasks.');
foreach (['all', 'unknown'] as $task) {
    [$status] = $request('GET', '/api/scheduler/triggers/' . $task, null, '', $basic);
    $assert($status === 404, 'Web-cron URLs select exactly one registered task.');
}
[$status, $execution] = $request('GET', '/api/scheduler/triggers/cleanup?task=feeds&options[cleanup_batch]=1', null, '', $basic + ['X-Test-Scheduler-Lock' => '0']);
$assert($status === 200 && $execution['data']['selected_task'] === 'cleanup' && $execution['data']['tasks']['cleanup']['status'] === 'already_running', 'Web-cron uses shared execution locks and selects the task from its fixed URL.');
[$status] = $request('GET', '/api/scheduler/triggers/cleanup', null, '', $basic + ['Sec-Fetch-Site' => 'cross-site']);
$assert($status === 403, 'Cross-site browser requests cannot trigger a web-cron.');
[$status] = $request('POST', '/api/scheduler/triggers/cleanup', [], '', $basic);
$assert($status === 405, 'Web-cron accepts only the documented GET and HEAD methods.');
[$status] = $request('GET', '/api/scheduler/health', null, '', $basic);
$assert($status === 401, 'Basic compatibility does not weaken Bearer-only scheduler endpoints.');
[$status] = $request('POST', '/api/scheduler/tokens', [], 'admin', $csrf);
[$status] = $request('GET', '/api/scheduler/triggers/cleanup', null, '', $basic);
$assert($status === 401, 'Token rotation revokes existing web-cron credentials.');
[$status] = $request('GET', '/api/site-health');
$assert($status === 401, 'Anonymous visitors cannot inspect site diagnostics.');
[$status] = $request('GET', '/api/site-health', null, 'user');
$assert($status === 403, 'Ordinary users cannot inspect site diagnostics.');
[$status] = $request('POST', '/api/site-health/checks', [], 'admin');
$assert($status === 403, 'Manual health checks require the shared CSRF guard.');
$noticeCount = (int) $systemDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
[$status, $healthSnapshot] = $request('GET', '/api/site-health', null, 'admin');
$assert($status === 200 && $healthSnapshot['data']['report'] === null && $healthSnapshot['data']['status'] === 'unknown', 'The initial health resource is an explicit unknown state.');
[$status, $healthPage] = $exportRequest('/admin/site-health', 'GET', ['X-Test-Role' => 'admin']);
$assert($status === 200 && !str_contains($healthPage, 'Undefined array key') && !str_contains($healthPage, 'Warning') && (int) $systemDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $noticeCount, 'Viewing site health renders the empty state cleanly without running diagnostics or sending notifications.');
[$status] = $request('POST', '/api/site-health/checks', [], 'admin', $csrf + ['X-Test-Scheduler-Lock' => '0']);
$assert($status === 409, 'Concurrent manual diagnostics use the same scheduler lock.');
[$status, $manualHealth] = $request('POST', '/api/site-health/checks', [], 'admin', $csrf);
$assert($status === 201 && $manualHealth['code'] === 'health_check_finished' && $manualHealth['data']['redirect'] === '/admin/site-health', 'Manual diagnostics use the canonical API and return resource data with a page redirect.');
[$status, $healthSnapshot] = $request('GET', '/api/site-health', null, 'admin');
$assert($status === 200 && count($healthSnapshot['data']['report']['checks']) === 9 && !$healthSnapshot['data']['stale'], 'The health resource exposes persisted check outcomes without executing new work.');
[$status, $healthPage] = $exportRequest('/admin/site-health', 'GET', ['X-Test-Role' => 'admin']);
$assert($status === 200 && !str_contains($healthPage, 'Undefined array key') && !str_contains($healthPage, 'Warning') && str_contains($healthPage, '/api/site-health/checks'), 'The completed diagnostic and history render through the same protected page.');
$adminCount = (int) $systemDb->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
$assert((int) $systemDb->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $noticeCount + $adminCount, 'Exactly the active administrators receive the completed HTTP inspection notification.');
$systemDb = null;
