<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cache-isolation.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP API HTTP contract: pdo_sqlite is unavailable.\n";
    exit(0);
}

$root = dirname(__DIR__, 2);
$temporary = $root . '/storage/api-contract-' . bin2hex(random_bytes(6));
mkdir($temporary, 0775, true);
$databasePath = $temporary . '/database.sqlite';
$database = new PDO('sqlite:' . $databasePath);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, email TEXT, password TEXT, role TEXT NOT NULL, status TEXT NOT NULL, locale TEXT, theme TEXT DEFAULT "system", avatar_exists INTEGER DEFAULT 0, bio TEXT DEFAULT "", email_notifications INTEGER DEFAULT 0, muted_until TEXT, muted_by INTEGER, muted_reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE content (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER, body TEXT, published_at TEXT, edit_locked_at TEXT, edit_locked_by INTEGER, edit_lock_reason TEXT)',
    'CREATE TABLE content_likes (content_id INTEGER NOT NULL, user_id INTEGER NOT NULL, UNIQUE(content_id, user_id))',
    'CREATE TABLE content_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, content_id INTEGER NOT NULL, parent_id INTEGER, user_id INTEGER NOT NULL, body TEXT, created_at TEXT, comments_diff TEXT)',
    'CREATE TABLE comment_likes (comment_id INTEGER NOT NULL, user_id INTEGER NOT NULL, UNIQUE(comment_id, user_id))',
    'CREATE TABLE terms (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)',
    'CREATE TABLE content_tags (content_id INTEGER NOT NULL, term_id INTEGER NOT NULL, UNIQUE(content_id, term_id))',
    'CREATE TABLE links (id INTEGER PRIMARY KEY AUTOINCREMENT, normalized_url TEXT NOT NULL, url_hash TEXT NOT NULL UNIQUE, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, created_at TEXT, updated_at TEXT)',
    'CREATE TABLE content_links (content_id INTEGER NOT NULL, link_id INTEGER NOT NULL, created_at TEXT, UNIQUE(content_id, link_id))',
    'CREATE TABLE content_images (content_id INTEGER PRIMARY KEY, path TEXT NOT NULL, width INTEGER, height INTEGER, bytes INTEGER)',
    'CREATE TABLE content_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, content_id INTEGER NOT NULL, reporter_id INTEGER, reason TEXT, note TEXT, status TEXT, created_at TEXT, updated_at TEXT, reviewed_at TEXT, reviewed_by INTEGER, action_note TEXT)',
    'CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, actor_id INTEGER, content_id INTEGER, comment_id INTEGER, type TEXT NOT NULL, notification_key TEXT NOT NULL, read_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(user_id, notification_key))',
    'CREATE TABLE user_followers (user_id INTEGER NOT NULL, follower_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, follower_id))',
    'CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT, expires_at TEXT, used_at TEXT)',
    'CREATE TABLE moderation_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, created_at TEXT)',
    'CREATE TABLE ip_action_limits (ip_address TEXT, action_name TEXT, bucket_start TEXT, action_count INTEGER, updated_at TEXT, UNIQUE(ip_address, action_name, bucket_start))',
    'CREATE TABLE bot_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, bot_user_id INTEGER NOT NULL, name TEXT NOT NULL, feed_url TEXT NOT NULL, feed_hash TEXT UNIQUE NOT NULL, interval_minutes INTEGER DEFAULT 60, post_template TEXT NOT NULL, enabled INTEGER DEFAULT 1, last_checked_at TEXT, last_imported_at TEXT, next_run_at TEXT, last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE bot_feed_history (bot_user_id INTEGER, feed_hash TEXT, item_hash TEXT, created_at TEXT, PRIMARY KEY (bot_user_id, feed_hash, item_hash))',
    'CREATE TABLE bot_source_runs (id INTEGER PRIMARY KEY, source_id INTEGER, status TEXT, started_at TEXT, items_seen INTEGER, error TEXT)',
    'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT UNIQUE NOT NULL, setting_value TEXT, setting_type TEXT, setting_group TEXT, autoload INTEGER DEFAULT 1)',
    "INSERT INTO users (id, username, role, status) VALUES (1, 'admin', 'admin', 'active'), (2, 'member', 'user', 'active'), (3, 'feedbot', 'bot', 'active')",
] as $sql) {
    $database->exec($sql);
}
$password = password_hash('Example-password-42', PASSWORD_DEFAULT);
$database->prepare('UPDATE users SET password = ?, locale = ?, email = ? WHERE id = 2')->execute([$password, 'en', 'member@example.test']);
$database->exec("INSERT INTO content (id, author_id, body, published_at) VALUES (10, 1, 'Hello @2 #test <img onerror=alert(1)>', '2026-01-01 12:00:00'), (11, 2, 'Member post', '2026-01-02 12:00:00')");
$database->exec("INSERT INTO content_comments (id, content_id, user_id, body, created_at) VALUES (10, 10, 2, 'A comment @1', '2026-01-01 12:01:00')");
$database->exec("INSERT INTO notifications (id, user_id, actor_id, content_id, type, notification_key, created_at, updated_at) VALUES (1, 2, 1, 10, 'content_like', 'fixture-own', '2026-01-01 12:02:00', '2026-01-01 12:02:00'), (2, 1, 2, 11, 'content_like', 'fixture-foreign', '2026-01-01 12:03:00', '2026-01-01 12:03:00')");
$database->exec("INSERT INTO settings (setting_key, setting_value, setting_type, setting_group) VALUES ('auth.registration.enabled', '1', 'bool', 'auth'), ('auth.registration.auto_approve', '1', 'bool', 'auth'), ('i18n.locale', 'en', 'string', 'i18n')");
$database = null;
$listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if (!is_resource($listener)) {
    throw new RuntimeException('Cannot select a local HTTP test port: ' . $errorMessage);
}
$address = stream_socket_get_name($listener, false);
fclose($listener);
$environment = getenv();
$environment = is_array($environment) ? $environment : [];
$pipes = [];
$server = proc_open(
    [PHP_BINARY, '-S', $address, $root . '/tests/http/api-contract-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']],
    $pipes,
    $root,
    [...$environment, 'TINYCAT_API_TEST_DATABASE' => $databasePath, 'TINYCAT_API_FOUNDATION_TEST' => '1']
);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message . ' Last response: ' . json_encode($GLOBALS['api_contract_last_response'] ?? null) . ' ' . ($GLOBALS['api_contract_server_error'] ?? ''));
    }
};
$request = static function (string $method, string $path, array|string|null $data = null, string $role = '', array $extra = []) use ($address, $assert, $temporary): array {
    $headers = ['Accept' => 'text/html', 'Content-Type' => 'application/json'];
    if ($role !== '') {
        $headers['X-Test-Role'] = $role;
    }
    foreach ($extra as $name => $value) {
        $headers[$name] = $value;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers))),
        'content' => is_string($data) ? $data : ($data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR)),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents('http://' . $address . $path, false, $context);
    $headers = http_get_last_response_headers() ?? [];
    $payload = json_decode((string) $body, true);
    $GLOBALS['api_contract_last_response'] = $payload;
    $assert(is_array($payload), "{$method} {$path} must return JSON even with an HTML Accept header: " . substr((string) $body, 0, 300));
    preg_match('/\s(\d{3})\s/', (string) ($headers[0] ?? ''), $status);
    $httpStatus = (int) ($status[1] ?? 0);
    $GLOBALS['api_contract_server_error'] = $httpStatus >= 500 ? substr((string) file_get_contents($temporary . '/server.log'), -1500) : '';
    $assert(($payload['status'] ?? null) === $httpStatus, "{$method} {$path} envelope status must equal HTTP status.");
    $assert(isset($payload['ok']) && array_key_exists('data', $payload) && array_key_exists('message', $payload) && array_key_exists('meta', $payload), 'Every response must use the shared envelope.');
    $assert(is_string($payload['code'] ?? null) && preg_match('/^[a-z][a-z0-9_]*$/D', $payload['code']) === 1 && is_array($payload['params'] ?? null), 'Every outcome exposes a stable domain code and parameter object.');
    foreach ($payload['error']['details']['errors'] ?? [] as $field => $issues) {
        foreach ($issues as $issue) {
            $assert(is_string($issue['code'] ?? null) && is_array($issue['params'] ?? null) && is_string($issue['message'] ?? null) && $issue['params']['field'] === $field, 'Field errors use code, params and readable fallback.');
        }
    }
    $assert(count(array_filter($headers, static fn (string $header): bool => str_starts_with(strtolower($header), 'content-type: application/json'))) === 1, 'API responses must declare JSON.');
    return [$httpStatus, $payload, $headers];
};

try {
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start the isolated HTTP API test server.');
    }
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            break;
        }
        usleep(50_000);
    }

    foreach (['/api', '/api/missing', '/api/admin/missing', '/api/status/unsupported', '/api/bots/sources/accounts-api'] as $path) {
        [$status, $payload] = $request('POST', $path);
        $assert($status === 404 && $payload['code'] === 'not_found', 'Unknown API endpoints must return the same 404.');
    }
    foreach (['/api/users', '/api/bots/accounts', '/api/bots/sources'] as $path) {
        [$status, $payload, $headers] = $request('PUT', $path);
        $assert($status === 405 && $payload['code'] === 'method_not_allowed', 'Unsupported methods must be rejected before handlers.');
        $assert(count(array_filter($headers, static fn (string $header): bool => str_starts_with($header, 'Allow: GET, POST'))) === 1, '405 responses must include explicit allowed methods.');
    }
    foreach (['/api/users', '/api/bots/sources', '/api/notifications', '/api/statuses'] as $path) {
        [$status, $payload] = $request($path === '/api/statuses' ? 'POST' : 'GET', $path);
        $assert($status === 401 && $payload['code'] === 'unauthenticated', 'Protected API routes must share the authentication error.');
    }
    [$status] = $request('GET', '/api/bots/sources', null, 'user');
    $assert($status === 403, 'A regular user cannot access bot administration.');
    foreach (['/api/users', '/api/bots/sources', '/api/bots/accounts', '/api/scheduler/tokens'] as $path) {
        [$status, $payload] = $request('POST', $path, [], 'admin');
        $assert($status === 403 && $payload['code'] === 'csrf_token_mismatch', 'All API mutations must use the shared CSRF guard.');
    }

    $csrf = ['X-CSRF-Token' => str_repeat('a', 64)];
    [$status, $payload] = $request('GET', '/api/foundation/items/7');
    $assert($status === 200 && $payload['data']['id'] === 7 && $payload['data']['value'] === '<b>literal user text</b>' && $payload['message'] === 'Loaded.' && $payload['meta']['source'] === 'module', 'Module APIs receive named parameters and return pure data, messages and metadata.');
    [$status, $payload] = $request('POST', '/api/foundation/items', ['value' => 'new'], 'admin', $csrf);
    $assert($status === 201 && $payload['data']['value'] === 'new', 'Module API writes share authentication, CSRF and request parsing.');
    foreach (['/api/foundation/bad-resource', '/api/foundation/bad-error', '/api/foundation/bad-ui'] as $path) {
        [$status, $payload] = $request('GET', $path);
        $assert($status === 500 && !str_contains(json_encode($payload), 'fragment'), 'Module APIs reject HTML resources and HTML error details.');
    }
    [$status] = $request('GET', '/api/foundation/private');
    $assert($status === 401, 'Module API enforces session access.');
    [$status] = $request('GET', '/api/foundation/private', null, 'user');
    $assert($status === 403, 'Module API enforces administrator access.');
    [$status, $payload] = $request('POST', '/api/foundation/validation', [], '', $csrf);
    $assert($status === 422 && isset($payload['error']['details']['errors']['name']) && !array_key_exists('errors', $payload), 'Module API has one validation-error representation without legacy aliases.');
    [$status, $payload] = $request('POST', '/api/bots/sources', ['_method' => []], 'admin', $csrf);
    $assert($status === 400, 'Malformed method overrides must not produce PHP diagnostics.');
    [$status, $payload] = $request('POST', '/api/bots/sources', ['_csrf' => []], 'admin');
    $assert($status === 403, 'Malformed CSRF tokens must not produce PHP diagnostics.');
    foreach (['0', '-1', 'invalid'] as $invalidId) {
        [$status] = $request('DELETE', '/api/bots/accounts/' . $invalidId, ['id' => 1], 'admin', $csrf);
        $assert($status === 404, 'Invalid path IDs cannot fall back to a body ID.');
    }
    [$status, $payload] = $request('POST', '/api/users/1/followers/me', [], 'user', $csrf);
    $assert($status === 405, 'Follow state must use PUT or DELETE, never an action toggle.');
    [$status, $payload] = $request('POST', '/api/bots/sources', '{invalid', 'admin', $csrf);
    $assert($status === 400 && $payload['code'] === 'bad_request', 'Malformed JSON must use the same error envelope.');
    [$status, $payload] = $request('GET', '/api/foundation/database-error', null, 'admin');
    $assert($status === 500 && $payload['message'] === 'Server error.' && !str_contains(json_encode($payload), 'SQL'), 'Unexpected database failures must remain JSON without leaking internals.');
    [$status, $payload] = $request('POST', '/api/bots/accounts', ['username' => 'Žaneta.Novák', 'status' => 'active'], 'admin', $csrf);
    $assert($status === 201 && $payload['data']['item']['username'] === 'Žaneta.Novák', 'Bot account creation must preserve Unicode and case and return 201.');
    $assert(is_string($payload['message']) && !isset($payload['data']['message']), 'Success messages must be exposed consistently.');

    $source = ['bot_user_id' => 3, 'name' => 'Contract feed', 'feed_url' => 'https://93.184.216.34/feed', 'interval_minutes' => 60, 'post_template' => '{{title}}', 'enabled' => '1'];
    [$status, $payload] = $request('POST', '/api/bots/sources', $source, 'admin', $csrf);
    $assert($status === 201 && $payload['data']['item']['name'] === 'Contract feed', 'Bot source creation must return the same 201 contract.');
    $sourceId = $payload['data']['id'];
    [$status, $payload] = $request('PATCH', '/api/bots/sources/' . $sourceId, ['enabled' => false], 'admin', $csrf);
    $assert($status === 200 && !$payload['data']['item']['enabled'], 'Named action parameters must dispatch through the same API middleware.');
    [$status, $payload] = $request('POST', '/api/bots/sources', $source, 'admin', $csrf);
    $assert($status === 422 && isset($payload['error']['details']['errors']['feed_url']), 'Duplicate source validation must use the shared validation envelope.');

    [$status, $payload] = $request('GET', '/api/bots/sources?view=html', null, 'admin');
    $assert($status === 200 && isset($payload['data']['items']) && !isset($payload['data']['html']), 'Legacy presentation requests still receive only JSON data.');
    [$status, $payload] = $request('GET', '/api/users?view=html', null, 'admin');
    $assert($status === 200 && isset($payload['data']['items'], $payload['meta']['pagination']) && !isset($payload['data']['html']), 'User administration must return only resources and canonical pagination even when HTML is requested.');
    [$status, $payload] = $request('POST', '/api/bots/sources/' . $sourceId, $source + ['_method' => 'PATCH', 'id' => 1], 'admin', $csrf);
    $assert($status === 200 && $payload['data']['id'] === $sourceId, 'POST method overrides must use the same registered PATCH route.');
    [$status] = $request('DELETE', '/api/bots/sources/' . $sourceId, ['id' => 1], 'admin', $csrf);
    $assert($status === 200, 'Source deletion must use the shared response contract.');
    require __DIR__ . '/domain-contract-cases.php';
    require __DIR__ . '/module-contract-cases.php';
    require __DIR__ . '/avatar-contract-cases.php';
    echo "PASS API HTTP contract ({$checks} checks)\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    foreach (glob($temporary . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($temporary);
}
