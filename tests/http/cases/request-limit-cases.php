<?php
declare(strict_types=1);

// Saturate deterministic buckets instead of making hundreds of expensive attempts.
$limitDb = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$limitDb->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'auth.registration.enabled'");
$seedLimit = static function (string $subject, string $action, int $window) use ($limitDb): void {
    $insert = $limitDb->prepare('INSERT INTO ip_action_limits (ip_address, action_name, bucket_start, action_count, updated_at)
        VALUES (?, ?, ?, 9999, ?) ON CONFLICT(ip_address, action_name, bucket_start) DO UPDATE SET action_count = 9999');
    $insert->execute([$subject, $action, gmdate('Y-m-d H:i:s', intdiv(time(), $window) * $window), gmdate('Y-m-d H:i:s')]);
};
foreach ([
    ['login', 900, '/api/users/sessions', '/login', []],
    ['registration', 3600, '/api/users/registrations', '/register', []],
    ['recovery', 900, '/api/users/password-resets', '/recovery', ['email' => 'absent@example.test']],
    ['recovery_confirm', 900, '/api/users/password-resets/confirm', '/recovery', ['action' => 'reset', 'token' => 'invalid']],
] as [$action, $window, $apiPath, $pagePath, $body]) {
    $seedLimit('127.0.0.1', $action, $window);
    [$status, $limited, $headers] = $request('POST', $apiPath, $body, '', $csrf);
    $wait = $limited['error']['details']['retry_after'] ?? 0;
    $assert($status === 429 && $limited['code'] === 'action_limited' && $wait >= 1 && $wait <= $window, $action . ' is rate limited without CAPTCHA.');
    $assert(in_array('Retry-After: ' . $wait, $headers, true), $action . ' exposes a matching wait header.');
    [$pageStatus] = $request('POST', $pagePath, $body, '', $csrf + ['Accept' => 'application/json']);
    $assert($pageStatus === 405, $action . ' no longer has a parallel HTML write handler.');
}

// Changes consume a follow budget, but already satisfied requests remain idempotent.
$limitDb->exec('DELETE FROM user_followers WHERE user_id = 1 AND follower_id = 2');
$seedLimit('user:2', 'follow', 3600);
[$status] = $request('PUT', '/api/users/1/followers/me', [], 'user', $csrf);
$assert($status === 429 && (int) $limitDb->query('SELECT COUNT(*) FROM user_followers WHERE user_id = 1 AND follower_id = 2')->fetchColumn() === 0, 'Exhausted follow budgets cannot change state.');
[$status] = $request('DELETE', '/api/users/1/followers/me', [], 'user', $csrf);
$assert($status === 200, 'An already satisfied unfollow does not consume a budget.');
$limitDb->exec('INSERT INTO user_followers (user_id, follower_id) VALUES (1, 2)');
[$status] = $request('PUT', '/api/users/1/followers/me', [], 'user', $csrf);
$assert($status === 200, 'An already satisfied follow does not consume a budget.');

$seedLimit('user:2', 'upload', 3600);
$boundary = 'rate-limit-' . bin2hex(random_bytes(8));
$multipart = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"_method\"\r\n\r\nPUT\r\n"
    . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"invalid.png\"\r\nContent-Type: image/png\r\n\r\ninvalid\r\n--" . $boundary . "--\r\n";
[$status, $limited] = $request('POST', '/api/users/2/avatar', $multipart, 'user', $csrf + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary]);
$assert($status === 429 && $limited['code'] === 'action_limited', 'Upload limits are enforced before image decoding.');
[$status] = $request('DELETE', '/api/users/2/avatar', [], 'user', $csrf);
$assert($status === 200, 'An exhausted upload budget does not prevent removing an avatar.');
$seedLimit('user:1', 'upload', 3600);
[$status, $invalidUpload] = $request('POST', '/api/users/2/avatar', $multipart, 'admin', $csrf + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary]);
$assert($status === 422 && $invalidUpload['code'] === 'avatar_invalid', 'Admins bypass even exhausted upload budgets and still undergo image validation.');
$seedLimit('user:1', 'follow', 3600);
[$status] = $request('PUT', '/api/users/2/followers/me', [], 'admin', $csrf);
$assert($status === 200, 'Admins bypass exhausted follow budgets.');
[$status] = $request('POST', '/api/users/sessions', ['username' => 'admin', 'role' => 'admin'], '', $csrf);
$assert($status === 429, 'A claimed administrator name or role cannot bypass anonymous login budgets.');
[$status] = $request('POST', '/logout', [], 'user', $csrf + ['Accept' => 'application/json']);
$assert($status === 405, 'Logout has no legacy page write handler.');
[$status, $loggedOut] = $request('DELETE', '/api/users/sessions/current', [], 'user', $csrf);
$assert($status === 200 && $loggedOut['data']['logged_out'], 'Logout uses the normalized API.');
foreach (['/admin', '/admin/updates', '/notifications', '/search', '/status/10', '/tag/test'] as $retiredPage) {
    [$status] = $request('POST', $retiredPage, [], 'admin', $csrf + ['Accept' => 'application/json']);
    $assert($status === 405, 'Page writes are retired: ' . $retiredPage);
}
$limitDb->exec("INSERT INTO notifications (id, user_id, actor_id, type, notification_key, created_at, updated_at) VALUES (900001, 2, 1, 'follow', 'rate-limits-api-open', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
[$status] = $exportRequest('/notifications/open?id=900001', 'GET', ['X-Test-Role' => 'user']);
$assert($status === 302 && $limitDb->query('SELECT read_at FROM notifications WHERE id = 900001')->fetchColumn() === null, 'Legacy notification navigation redirects without mutating read state.');
[$status, $opened] = $request('PATCH', '/api/notifications/900001?open=1', [], 'user', $csrf);
$assert($status === 200 && $opened['data']['redirect'] === '/author/1' && $limitDb->query('SELECT read_at FROM notifications WHERE id = 900001')->fetchColumn() !== null, 'Read-and-open writes use the CSRF-protected API.');
$seedLimit = null;
$limitDb = null;
