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
$assert($status === 200 && $health['code'] === 'scheduler_healthy' && $health['data']['available_tasks'] === ['feeds', 'cleanup'], 'Authenticated scheduler health uses the standard resource envelope.');
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
$systemDb = null;
