<?php
declare(strict_types=1);

// Uses the disposable database and HTTP server from api-contract.php.
$seed = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['/api/admin/bot-accounts', '/api/admin/bots', '/api/admin/settings', '/api/admin/moderation/reports', '/api/admin/cron-token', '/api/sidebar', '/api/status-suggest', '/api/search-captcha'] as $retired) {
    [$status] = $request('GET', $retired, null, 'admin');
    $assert($status === 404, 'Retired endpoints have no compatibility aliases: ' . $retired);
}
foreach (['/api/settings', '/api/moderation/reports', '/api/moderation/blocked-urls', '/api/bots/accounts'] as $private) {
    [$status] = $request('GET', $private, null, 'user');
    $assert($status === 403, 'Remaining modules retain administrator authorization.');
}
$seed->exec("INSERT INTO settings (setting_key, setting_value, setting_type, setting_group) VALUES ('email.smtp', '{\"password\":\"fixture-secret\"}', 'json', 'email')");
[$status, $payload] = $request('GET', '/api/settings?view=html', null, 'admin');
$assert($status === 200 && !str_contains(json_encode($payload), 'fixture-secret'), 'Settings resources never disclose SMTP passwords.');
$pure($payload);
[$status, $payload] = $request('PATCH', '/api/settings', ['settings' => ['site.name' => 'Modular fixture', 'auth.registration.enabled' => false]], 'admin', $csrf);
$assert($status === 200 && $payload['data'] === ['saved' => true], 'Settings PATCH accepts partial updates and returns only confirmation data.');
$assert($seed->query("SELECT setting_value FROM settings WHERE setting_key = 'i18n.locale'")->fetchColumn() === 'en', 'Partial settings changes preserve omitted settings.');
$assert($seed->query("SELECT setting_value FROM settings WHERE setting_key = 'auth.registration.enabled'")->fetchColumn() === '0', 'A JSON false value disables a setting.');
foreach ([['settings' => 'invalid'], ['settings' => ['site.name' => []]], ['settings' => ['email.smtp' => ['password' => []]]]] as $invalid) {
    [$status, $payload] = $request('PATCH', '/api/settings', $invalid, 'admin', $csrf);
    $assert($status === 422 && isset($payload['error']['details']['errors']), 'Malformed settings receive the shared validation envelope.');
}
[$status, $payload] = $request('POST', '/api/scheduler/tokens', [], 'admin', $csrf);
$assert($status === 201 && strlen($payload['data']['token']) >= 32, 'Token rotation returns the new token directly to its administrator.');
$pure($payload);
[$status, $payload] = $request('PUT', '/api/moderation/blocked-urls', ['blocked_urls' => 'example.org'], 'admin', $csrf);
$assert($status === 200 && in_array('example.org', $payload['data']['items'], true), 'URL blocking uses a data response without a redirect.');
$assert(!isset($payload['data']['redirect']), 'Inline moderation writes preserve AJAX.');
[$status] = $request('PATCH', '/api/moderation/reports/999999', ['decision' => 'dismiss'], 'admin', $csrf);
$assert($status === 409, 'Reviewing a missing or already closed report returns a conflict.');
$seed->exec("INSERT INTO content (id, author_id, body, published_at) VALUES (9000, 2, 'Review fixture', '2026-01-01 12:00:00')");
$seed->exec("INSERT INTO content_reports (id, content_id, reporter_id, reason, status, created_at) VALUES (9000, 9000, 1, 'other', 'open', '2026-01-01 12:01:00')");
[$status, $payload] = $request('PATCH', '/api/moderation/reports/9000', ['decision' => 'dismiss', 'report_id' => 1], 'admin', $csrf);
$assert($status === 200 && $seed->query('SELECT status FROM content_reports WHERE id = 9000')->fetchColumn() === 'dismissed', 'Reviewing a report targets its path ID and updates the report.');
$pure($payload);
$assert(!str_contains(json_encode($payload), 'status_badge_class'), 'Moderation resources do not contain DOM styling instructions.');
[$status, $payload] = $request('GET', '/api/site/sidebar');
$assert($status === 200 && isset($payload['data']['tags'], $payload['data']['authors'], $payload['data']['footer']), 'The sidebar exposes resources and structured footer nodes.');
$pure($payload);
foreach ([['POST', '/api/bots/accounts', ['username' => []]], ['GET', '/api/search?q[]=invalid', null], ['PATCH', '/api/moderation/reports/9000', ['decision' => []]]] as [$verb, $path, $input]) {
    [$status, $payload] = $request($verb, $path, $input, 'admin', $csrf);
    $assert($status === 422, 'Malformed scalar fields fail validation without PHP diagnostics.');
}
foreach (['/api/bots/accounts?q=feedbot', '/api/users?q=admin'] as $filtered) {
    [$status, $payload] = $request('GET', $filtered, null, 'admin');
    $assert($status === 200 && count($payload['data']['items']) === 1, 'Account filtering works through the canonical JSON collection.');
}
[$status, $payload] = $request('GET', '/api/bots/accounts?q=%25', null, 'admin');
$assert($status === 200 && $payload['data']['items'] === [], 'Literal wildcard characters cannot broaden an account search.');
$seed = null;
