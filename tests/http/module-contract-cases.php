<?php
declare(strict_types=1);

// Uses the disposable database and HTTP server from api-contract.php.
$seed = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['GET', 'POST'] as $retiredSettingsMethod) {
    $retiredSettings = file_get_contents('http://' . $address . '/admin/settings', false, stream_context_create(['http' => ['method' => $retiredSettingsMethod, 'header' => "X-Test-Role: admin\r\nAccept: text/html", 'ignore_errors' => true]]));
    $retiredHeaders = http_get_last_response_headers() ?? [];
    $assert(preg_match('/\s404\s/', (string) ($retiredHeaders[0] ?? '')) === 1, 'The standalone settings page is retired for ' . $retiredSettingsMethod . '.');
}
$settingsHostPage = file_get_contents('http://' . $address . '/admin', false, stream_context_create(['http' => ['header' => "X-Test-Role: admin\r\nAccept: text/html", 'ignore_errors' => true]]));
$assert(is_string($settingsHostPage) && str_contains($settingsHostPage, 'data-modal-open="modal-settings-editor"') && str_contains($settingsHostPage, 'data-modal-url="/api/settings/editor"') && !str_contains($settingsHostPage, 'data-settings-editor') && !str_contains($settingsHostPage, 'href="/admin/settings"'), 'Admin navigation opens the shared settings modal without a standalone page.');
$assert(!str_contains($settingsHostPage, 'name="settings[') && !str_contains($settingsHostPage, 'id="modal-settings-editor"') && !str_contains($settingsHostPage, 'href="/admin/moderation/blocking"'), 'Settings fields and editor DOM are absent from the initial page and blocked websites have no separate navigation page.');
file_get_contents('http://' . $address . '/admin/moderation/blocking', false, stream_context_create(['http' => ['header' => "X-Test-Role: admin\r\nAccept: text/html", 'ignore_errors' => true]]));
$blockingHeaders = http_get_last_response_headers() ?? [];
$assert(preg_match('/\s404\s/', (string) ($blockingHeaders[0] ?? '')) === 1, 'The standalone URL blocking page is retired.');
foreach (['/api/admin/bot-accounts', '/api/admin/bots', '/api/admin/settings', '/api/admin/moderation/reports', '/api/admin/cron-token', '/api/sidebar', '/api/status-suggest', '/api/search-captcha'] as $retired) {
    [$status] = $request('GET', $retired, null, 'admin');
    $assert($status === 404, 'Retired endpoints have no compatibility aliases: ' . $retired);
}
foreach (['/api/users/creation', '/api/bots/accounts/creation', '/api/updates/release-notes', '/api/settings/editor', '/api/settings', '/api/moderation/reports', '/api/moderation/blocked-urls', '/api/bots/accounts'] as $private) {
    [$status] = $request('GET', $private, null, 'user');
    $assert($status === 403, 'Remaining modules retain administrator authorization.');
}
foreach (['/api/users/creation' => 'roles', '/api/bots/accounts/creation' => 'username_pattern'] as $endpoint => $option) {
    [$status, $creation] = $request('GET', $endpoint, null, 'admin');
    $assert($status === 200 && isset($creation['data']['options'][$option]), 'Creation metadata loads through an authorized resource: ' . $endpoint);
    $pure($creation);
}
foreach (['/admin/users' => 'modal-users-creation', '/admin/bots/accounts' => 'modal-bots-accounts-creation'] as $page => $id) {
    $html = file_get_contents('http://' . $address . $page, false, stream_context_create(['http' => ['header' => "X-Test-Role: admin\r\nAccept: text/html", 'ignore_errors' => true]]));
    $assert(str_contains($html, 'data-modal-open="' . $id . '"') && !str_contains($html, 'id="' . $id . '"') && !str_contains($html, 'class="modal"'), 'Creation pages contain resource triggers, never hidden dialogs: ' . $page);
}
[$status] = $request('GET', '/api/updates/release-notes', null, 'admin');
$assert($status === 404, 'Missing release notes return a normal resource 404 without fetching remote releases.');
$seed->exec("INSERT INTO settings (setting_key, setting_value, setting_type, setting_group) VALUES ('email.smtp', '{\"password\":\"fixture-secret\"}', 'json', 'email')");
[$status, $payload] = $request('GET', '/api/settings?view=html', null, 'admin');
$assert($status === 200 && !str_contains(json_encode($payload), 'fixture-secret'), 'Settings resources never disclose SMTP passwords.');
$pure($payload);
[$status, $schema] = $request('GET', '/api/settings/editor', null, 'admin');
$assert($status === 200 && count($schema['data']['sections']) >= 2 && !str_contains(json_encode($schema), 'fixture-secret'), 'Deferred settings editor is authorized, complete and never exposes saved passwords.');
$pure($schema);
$fields = array_merge(...array_column($schema['data']['sections'], 'fields'));
$assert(in_array('moderation.blocked_urls', array_column($fields, 'key'), true), 'Remote settings include URL blocking.');
[$status, $payload] = $request('PATCH', '/api/settings', ['settings' => ['site.name' => 'Modular fixture', 'auth.registration.enabled' => false]], 'admin', $csrf);
$assert($status === 200 && $payload['data']['saved'] && array_keys($payload['data']['images']) === ['site.logo_url', 'site.favicon_url'], 'Settings PATCH returns confirmation and saved public image paths for repeated AJAX saves.');
$assert($seed->query("SELECT setting_value FROM settings WHERE setting_key = 'i18n.locale'")->fetchColumn() === 'en', 'Partial settings changes preserve omitted settings.');
$assert($seed->query("SELECT setting_value FROM settings WHERE setting_key = 'auth.registration.enabled'")->fetchColumn() === '0', 'A JSON false value disables a setting.');
foreach ([['settings' => 'invalid'], ['settings' => ['site.name' => []]], ['settings' => ['email.smtp' => ['password' => []]]]] as $invalid) {
    [$status, $payload] = $request('PATCH', '/api/settings', $invalid, 'admin', $csrf);
    $assert($status === 422 && isset($payload['error']['details']['errors']), 'Malformed settings receive the shared validation envelope.');
}
[$status, $payload] = $request('POST', '/api/scheduler/tokens', [], 'admin', $csrf);
$assert($status === 201 && strlen($payload['data']['token']) >= 32, 'Token rotation returns the new token directly to its administrator.');
$pure($payload);
[$status] = $request('PATCH', '/api/settings', ['settings' => ['moderation.blocked_urls' => "https://WWW.Example.org/path\nexample.org; domain:ru; invalid"]], 'admin', $csrf);
$assert($status === 200 && $seed->query("SELECT setting_value FROM settings WHERE setting_key = 'moderation.blocked_urls'")->fetchColumn() === 'example.org, domain:ru', 'Settings normalize blocked hosts, remove duplicates and preserve domain rules.');
[$status] = $request('PATCH', '/api/settings', ['settings' => ['moderation.blocked_urls' => '']], 'admin', $csrf);
$assert($status === 200 && $seed->query("SELECT setting_value FROM settings WHERE setting_key = 'moderation.blocked_urls'")->fetchColumn() === '', 'Settings can clear the blocking rules.');
[$status, $payload] = $request('PUT', '/api/moderation/blocked-urls', ['blocked_urls' => 'example.org'], 'admin', $csrf);
$assert($status === 200 && in_array('example.org', $payload['data']['items'], true), 'URL blocking uses a data response without a redirect.');
$assert(!isset($payload['data']['redirect']), 'Inline moderation writes preserve AJAX.');
[$status, $created] = $request('POST', '/api/statuses', ['body' => 'Immediate API publication fixture', 'schedule_post' => '1', 'scheduled_at' => '2099-01-01T12:00'], 'admin', $csrf);
$assert($status === 201 && $created['code'] === 'status_created' && !str_starts_with($created['data']['item']['published_at'], '2099'), 'Obsolete scheduling inputs cannot create future posts through the API.');
$assert(!array_key_exists('scheduled', $created['data']['item']), 'Post resources no longer expose scheduling metadata.');
$seed->prepare('DELETE FROM content WHERE id = ?')->execute([$created['data']['status_id']]);
[$status] = $request('POST', '/api/scheduler/tasks/publish/runs', [], 'admin', $csrf);
$assert($status === 404, 'The removed publication-notification task is no longer available.');
[$status, $payload] = $request('POST', '/api/statuses', ['body' => 'https://example.org/blocked'], 'admin', $csrf);
$assert($status === 422 && $payload['code'] === 'blocked_url' && $payload['params'] === ['host' => 'example.org'], 'Post creation maps shared URL rejection to the existing HTTP contract.');
$assert($payload['error']['details']['host'] === 'example.org', 'Blocked URL responses retain their existing error details.');
[$status, $payload] = $request('POST', '/api/statuses', ['body' => '#' . str_repeat('a', 33)], 'admin', $csrf);
$assert($status === 422 && $payload['code'] === 'status_tag_too_long' && $payload['params'] === ['max' => '32'], 'Post creation maps shared tag rejection to the existing HTTP contract.');
[$status, $payload] = $request('PATCH', '/api/statuses/10', ['body' => 'https://example.org/blocked'], 'admin', $csrf);
$assert($status === 422 && $payload['code'] === 'blocked_url', 'Post edits use the same shared content validation.');
$assert($seed->query('SELECT body FROM content WHERE id = 10')->fetchColumn() !== 'https://example.org/blocked', 'Rejected edits do not modify stored posts.');
[$status] = $request('PATCH', '/api/moderation/reports/999999', ['decision' => 'dismiss'], 'admin', $csrf);
$assert($status === 409, 'Reviewing a missing or already closed report returns a conflict.');
$seed->exec("INSERT INTO content (id, author_id, body, published_at) VALUES (9000, 2, 'Review fixture', '2026-01-01 12:00:00')");
$seed->exec("INSERT INTO content_reports (id, content_id, reporter_id, reason, status, created_at) VALUES (9000, 9000, 1, 'other', 'open', '2026-01-01 12:01:00')");
[$status, $reportList] = $request('GET', '/api/moderation/reports?q=Review%20fixture&status=open&reason=other&per_page=10', null, 'admin');
$assert($status === 200 && count($reportList['data']['items']) === 1 && $reportList['data']['items'][0]['id'] === 9000, 'Report search, status and reason filters compose on the grouped list.');
$assert($reportList['meta']['pagination']['per_page'] === 10 && $reportList['meta']['pagination']['total'] === 1, 'Reports expose shared pagination metadata.');
$assert(str_contains($reportList['data']['items'][0]['action_url'], 'status=open') && str_contains($reportList['data']['sorting']['content']['url'], 'reason=other'), 'Review actions and sorting preserve active report filters.');
[$status] = $request('GET', '/api/moderation/reports/9000', null, 'user');
$assert($status === 403, 'Report notes and preserved evidence are restricted to administrators.');
[$status] = $request('GET', '/api/moderation/reports/9000');
$assert($status === 401, 'Anonymous visitors cannot load report details.');
[$status, $reportDetail] = $request('GET', '/api/moderation/reports/9000', null, 'admin');
$assert($status === 200 && $reportDetail['data']['preview']['item']['body'] === 'Review fixture', 'Report details use the canonical frontend post resource.');
$assert(array_keys($reportDetail['data']['content']) === ['id', 'status'] && !isset($reportDetail['data']['reports'][0]['reviewed_by']), 'Report details expose resource fields instead of raw database rows.');
$assert(str_contains($reportDetail['data']['reports'][0]['created_at'], 'T'), 'Report history timestamps use the shared ISO date contract.');
[$status, $reportOptions] = $request('GET', '/api/moderation/report-options?id=9000', null, 'admin');
$assert($status === 200 && $reportOptions['data']['note_max_length'] === 1000, 'Report options publish the server-owned note limit.');
[$status, $tooLongReport] = $request('POST', '/api/moderation/reports', ['id' => 9000, 'reason' => 'spam', 'note' => str_repeat('x', 1001)], 'admin', $csrf);
$assert($status === 422 && isset($tooLongReport['error']['details']['errors']['note']), 'Long report notes fail visibly instead of being silently truncated.');
[$status, $closedReports] = $request('GET', '/api/moderation/reports?q=Review%20fixture&status=dismissed', null, 'admin');
$assert($status === 200 && $closedReports['data']['items'] === [], 'Open reports are excluded from the dismissed filter.');
[$status, $wrongReason] = $request('GET', '/api/moderation/reports?q=Review%20fixture&reason=spam', null, 'admin');
$assert($status === 200 && $wrongReason['data']['items'] === [], 'Report reason filtering excludes other reasons.');
[$status, $payload] = $request('PATCH', '/api/moderation/reports/9000', ['decision' => 'dismiss', 'report_id' => 1], 'admin', $csrf);
$assert($status === 200 && $seed->query('SELECT status FROM content_reports WHERE id = 9000')->fetchColumn() === 'dismissed', 'Reviewing a report targets its path ID and updates the report.');
$pure($payload);
$assert(!str_contains(json_encode($payload), 'status_badge_class'), 'Moderation resources do not contain DOM styling instructions.');
[$status, $again] = $request('POST', '/api/moderation/reports', ['id' => 9000, 'reason' => 'spam', 'note' => 'New evidence after dismissal'], 'admin', $csrf);
$assert($status === 201 && $again['code'] === 'report_created', 'Closed reports do not permanently prevent reporting the same post again.');
$newReportId = (int) $seed->query("SELECT id FROM content_reports WHERE content_id = 9000 AND status = 'open'")->fetchColumn();
$assert($newReportId !== 9000 && $seed->query('SELECT status FROM content WHERE id = 9000')->fetchColumn() === 'published', 'A new report retains the earlier decision and does not hide the post.');
[$status] = $request('POST', '/api/moderation/reports', ['id' => 9000, 'reason' => 'abuse', 'note' => 'Updated open report'], 'admin', $csrf);
$assert($status === 201 && (int) $seed->query("SELECT COUNT(*) FROM content_reports WHERE content_id = 9000 AND reporter_id = 1 AND status = 'open'")->fetchColumn() === 1, 'One reporter has at most one open report for a post.');
[$status] = $request('PATCH', '/api/moderation/reports/' . $newReportId, ['decision' => 'remove'], 'admin', $csrf);
$assert($status === 200 && $seed->query('SELECT status FROM content WHERE id = 9000')->fetchColumn() === 'hidden', 'Only an explicit moderation hide decision hides the post.');
[$status] = $request('GET', '/api/statuses/9000', null, 'user');
$assert($status === 404, 'Hidden posts stay unavailable through the public detail even to signed-in users.');
[$status] = $request('GET', '/api/statuses/9000?panel=edit');
$assert($status === 401, 'Private edit reads require authentication.');
[$status, $hiddenEditor] = $request('GET', '/api/statuses/9000?panel=edit', null, 'user');
$assert($status === 200 && $hiddenEditor['data']['item']['status'] === 'hidden' && !$hiddenEditor['data']['item']['capabilities']['interact'], 'An owner can load a private hidden-post editor without enabling public interactions.');
$seed->exec('UPDATE users SET muted_until = NULL WHERE id = 2');
[$status, $hiddenEdit] = $request('PATCH', '/api/statuses/9000', ['body' => 'Review fixture corrected privately', 'status' => 'published'], 'user', $csrf);
$assert($status === 200 && $hiddenEdit['data']['item']['status'] === 'hidden' && $seed->query('SELECT status FROM content WHERE id = 9000')->fetchColumn() === 'hidden', 'Editing and forged status fields cannot publish hidden content.');
[$status] = $request('POST', '/api/moderation/posts/9000/publication', [], 'user', $csrf);
$assert($status === 403, 'The author cannot use the administrator publication endpoint.');
[$status] = $request('POST', '/api/moderation/posts/9000/publication', [], 'admin');
$assert($status === 403, 'Administrative publication requires CSRF protection.');
[$status, $published] = $request('POST', '/api/moderation/posts/9000/publication', [], 'admin', $csrf);
$assert($status === 200 && $published['code'] === 'content_published' && $seed->query('SELECT status FROM content WHERE id = 9000')->fetchColumn() === 'published', 'Explicit administrative publication restores visibility.');
$assert($seed->query('SELECT status FROM content_reports WHERE id = ' . $newReportId)->fetchColumn() === 'resolved' && $seed->query('SELECT status FROM content_reports WHERE id = 9000')->fetchColumn() === 'dismissed', 'Publishing preserves earlier report outcomes.');
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
// Search results and autocomplete have independent contracts.
$seed->exec("INSERT INTO content (id, author_id, body, published_at) VALUES (9100, 2, 'quartz bright nebula', '2026-01-01 12:00:00'), (9101, 2, 'unrelated post', '2026-01-02 12:00:00')");
[$status, $payload] = $request('GET', '/api/search/results?q=nebula%20quartz');
$assert($status === 200 && array_column($payload['data']['items'], 'id') === [9100], 'Full search matches all words in any order.');
$assert($payload['meta']['pagination']['done'] && $payload['meta']['pagination']['next_url'] === '', 'Search uses the standard feed cursor envelope.');
$pure($payload);
[$status, $payload] = $request('GET', '/api/search?q=quartz');
$assert($status === 200 && $payload['data']['content'] === [], 'Autocomplete returns no post results.');
foreach (['/api/search?q=' . str_repeat('a', 81), '/api/search/results?q=quartz&type=invalid', '/api/search/results?q=quartz&cursor_id=-1', '/api/search/results?q[]=invalid'] as $path) {
    [$status] = $request('GET', $path);
    $assert($status === 422, 'Search rejects invalid query and pagination parameters.');
}
[$status, $html] = $exportRequest('/search?q=nebula%20quartz');
$assert($status === 200 && str_contains($html, 'quartz bright nebula') && !str_contains($html, 'unrelated post'), 'Search documents render matching posts without unrelated author posts.');
[$status, $html] = $exportRequest('/search?q=nebula%20quartz&type=users');
$assert($status === 200 && str_contains($html, 'quartz bright nebula') && !str_contains($html, 'unrelated post'), 'Retired category URLs render matching posts.');
$assert(str_contains($html, 'archive-filter') && !str_contains($html, 'type=tags'), 'Search documents expose media filters instead of category navigation.');
[$status] = $exportRequest('/search?q[]=invalid');
$assert($status === 422, 'Malformed document queries fail without PHP warnings.');
[$status, $payload] = $request('GET', '/api/search/results?q=quartz%22');
$assert($status === 200 && $payload['data']['query'] === 'quartz"' && array_column($payload['data']['items'], 'id') === [9100], 'Quoted queries retain their text and find the unquoted word.');
[$status, $html] = $exportRequest('/search?q=%22%3Cquartz%3E%22');
$document = new DOMDocument(); libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors();
$input = (new DOMXPath($document))->query('//input[@id="global-search-input"]')->item(0);
$assert($status === 200 && $input->getAttribute('value') === '"<quartz>"' && str_contains($html, 'quartz bright nebula'), 'HTML search preserves and safely escapes quotes and angle brackets while matching words.');
$insertCursor = $seed->prepare('INSERT INTO content (id, author_id, body, published_at) VALUES (?, 2, ?, ?)');
for ($i = 0; $i < 25; $i++) { $insertCursor->execute([9200 + $i, 'cursorfixture ' . $i, '2020-01-01 12:00:00']); }
[$status, $firstBatch] = $request('GET', '/api/search/results?q=cursorfixture');
$assert($status === 200 && count($firstBatch['data']['items']) === 20 && !$firstBatch['meta']['pagination']['done'], 'Search exposes the standard feed continuation URL.');
$insertCursor->execute([9230, 'cursorfixture newer insertion', '2025-01-01 12:00:00']);
[$status, $nextBatch] = $request('GET', $firstBatch['meta']['pagination']['next_url']);
$allIds = array_column([...$firstBatch['data']['items'], ...$nextBatch['data']['items']], 'id');
$assert($status === 200 && count($nextBatch['data']['items']) === 5 && $nextBatch['meta']['pagination']['done'] && count(array_unique($allIds)) === 25 && !in_array(9230, $allIds, true), 'HTTP search cursors survive new posts and ties without duplicate or missing results.');
[$status, $html] = $exportRequest('/search?q=cursorfixture');
$assert($status === 200 && str_contains($html, 'data-status-feed-more') && str_contains($html, 'cursor_at') && !str_contains($html, 'rel="next"'), 'Search documents use the existing feed loader instead of numbered pagination.');
foreach (['content', 'users', 'tags'] as $type) {
    [$status, $resource] = $request('GET', '/api/site/pages?path=/search&q=quartz&type=' . $type);
    $assert($status === 200 && $resource['data']['kind'] === 'search' && $resource['data']['sidebar'] && $resource['data']['search']['type'] === 'content', 'Legacy search category parameters retain the shared post feed and sidebar.');
    $pure($resource);
}
[$status, $resource] = $request('GET', '/api/site/pages?path=/cs/search&q=quartz');
$assert($status === 200 && $resource['data']['locale'] === 'cs' && str_contains($resource['data']['url'], '/cs/search?'), 'Search navigation preserves locale and query parameters.');
[$status, $html] = $exportRequest('/search?q=quartz');
$assert($status === 200 && str_contains($html, 'data-public-page="search"') && str_contains($html, 'data-public-sidebar-slot'), 'Search initial HTML uses the same public page component and sidebar placeholder.');
[$status, $state] = $request('PATCH', '/api/notifications', ['read' => true], 'user', $csrf);
$assert($status === 200 && $state['data']['unread_count'] === 0, 'Mark-all clears the unread state.');
[$status, $html] = $exportRequest('/notifications', 'GET', ['X-Test-Role' => 'user']);
$doc = new DOMDocument(); libxml_use_internal_errors(true); $doc->loadHTML($html); libxml_clear_errors();
$assert($status === 200 && (new DOMXPath($doc))->query('//form[@action="/api/notifications" and @hidden]')->length === 1, 'An empty inbox retains a hidden reusable mark-all control.');
$seed->exec("INSERT INTO notifications (user_id, actor_id, type, notification_key, created_at, updated_at) VALUES (2, 1, 'follow', 'reactivate-read-all', '2026-03-01 00:00:00', '2026-03-01 00:00:00')");
[$status, $html] = $exportRequest('/notifications', 'GET', ['X-Test-Role' => 'user']);
$doc->loadHTML($html);
$assert((new DOMXPath($doc))->query('//form[@action="/api/notifications" and not(@hidden)]')->length === 1, 'New notifications make mark-all available again.');
$insertSource = $seed->prepare('INSERT INTO bot_sources (id, bot_user_id, name, feed_url, feed_hash, post_template) VALUES (?, 3, ?, ?, ?, ?)');
foreach (range(1, 12) as $number) {
    $insertSource->execute([96000 + $number, 'Layout fixture ' . sprintf('%02d', $number), 'https://example.test/layout/' . $number, hash('sha256', 'layout-fixture-' . $number), '{{title}}']);
}
[$status, $sourceList] = $request('GET', '/api/bots/sources?q=Layout%20fixture&bot=3&per_page=10&sort=name&direction=asc', null, 'admin');
$assert($status === 200 && count($sourceList['data']['items']) === 10 && $sourceList['meta']['pagination']['total'] === 12, 'Sources combine search and bot filters with shared pagination.');
[$status, $sourcePage] = $request('GET', '/api/bots/sources?q=Layout%20fixture&bot=3&per_page=10&page=2&sort=name&direction=asc', null, 'admin');
$assert($status === 200 && array_column($sourcePage['data']['items'], 'id') === [96011, 96012], 'Source sorting has a stable filtered second page.');
[$status] = $request('GET', '/api/bots/sources/96001/management', null, 'user');
$assert($status === 403, 'Only administrators can load source editors.');
$seed->exec("UPDATE bot_sources SET name = 'Fresh editor value' WHERE id = 96001");
[$status, $sourceEditor] = $request('GET', '/api/bots/sources/96001/management', null, 'admin');
$assert($status === 200 && $sourceEditor['data']['item']['name'] === 'Fresh editor value' && $sourceEditor['data']['bots'] !== [], 'Source editors load current values and available bot accounts.');
[$status] = $request('GET', '/api/bots/sources/999999/management', null, 'admin');
$assert($status === 404, 'Missing sources do not open an empty editor.');
$seed->exec('DELETE FROM bot_sources WHERE id BETWEEN 96001 AND 96012');
$insertSource = null;
$insertCursor = null;
$seed = null;
