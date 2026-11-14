<?php
declare(strict_types=1);

use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Readiness;
use TinyCat\Core\Identity\Roles;
use TinyCat\Database\Connection;

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 3);
require $root . '/App/autoload.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
try { throw new \TinyCat\Core\Identity\ValidationException(['email' => ['invalid']]); }
catch (\TinyCat\Core\Identity\ValidationException $error) { $assert($error->issues === ['email' => ['invalid']], 'Legacy catch clauses match canonical identity exceptions without a prior legacy load'); }
$assert(new \TinyCat\Core\Identity\Participant() instanceof \TinyCat\Core\Identity\Participant, 'Legacy participant instanceof remains valid');
$assert(new \TinyCat\Core\Identity\Deletion() instanceof \TinyCat\Core\Identity\Deletion, 'Legacy deletion instanceof remains valid');
$assert(interface_exists(\TinyCat\Core\Identity\ProvidesLifecycle::class) && interface_exists(\TinyCat\Core\Identity\ProvidesLifecycle::class, false), 'Legacy lifecycle interface is registered with its canonical contract');
$directory = $root . '/storage/account-identity-' . bin2hex(random_bytes(8));
mkdir($directory . '/sessions', 0700, true);
$server = null;
$pdo = new PDO('sqlite:' . $directory . '/identity.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    Connection::set($pdo);
    $assert(!Readiness::ready(), 'Missing schema is not ready');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT COLLATE NOCASE, email TEXT COLLATE NOCASE, password TEXT, role TEXT, status TEXT)');
    $assert(!Readiness::ready(), 'Empty accounts are not ready');
    $password = 'Identity-fixture-42';
    $hash = Authentication::authPassword($password);
    $insert = $pdo->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([[1, 'Admin', 'admin@example.test', $hash, 'admin', 'active'], [2, 'Member', 'member@example.test', $hash, 'user', 'active'],
        [3, 'Bot', null, $hash, 'bot', 'active'], [4, 'Banned', null, $hash, 'user', 'ban'], [5, 'Waiting', null, $hash, 'user', 'waiting'],
        [6, 'Unknown', null, $hash, 'unknown', 'active']] as $row) { $insert->execute($row); }
    $assert(Readiness::ready(), 'Accounts readiness needs only the users table');
    $assert(Roles::rolesWith('allows_login') === ['admin', 'user'] && Roles::rolesWith('managed_by_core_admin') === ['admin', 'user'], 'Account capabilities retain order and meaning');
    $assert(!Roles::allowsLogin('bot') && !Roles::allowsLogin('unknown'), 'Noninteractive and unknown roles cannot log in');
    foreach (['TinyCat\\Social\\Profiles\\Roles', 'TinyCat\\Social\\Profiles\\Profiles', 'TinyCat\\Social\\Posts\\Registration', 'TinyCat\\Social\\Automation\\Registration', 'TinyCat\\Core\\Settings\\Store'] as $class) {
        $assert(!class_exists($class, false), 'Standalone identity did not load ' . $class);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) { throw new RuntimeException('Cannot allocate identity server port.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $environment = array_merge(getenv(), ['TINYCAT_IDENTITY_DIRECTORY' => $directory]);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $directory . '/sessions', '-S', $address, $root . '/tests/fixtures/accounts/identity-worker.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes, $root, $environment);
    if (!is_resource($server)) { throw new RuntimeException('Cannot start identity server.'); }
    fclose($pipes[0]);
    $request = static function (string $path, array &$cookies, string $method = 'GET', ?array $payload = null, string $csrf = '', string $accept = 'application/json') use ($address): array {
        $handle = curl_init('http://' . $address . $path);
        $headers = ['Accept: ' . $accept]; $responseHeaders = [];
        if ($csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
        if ($cookies !== []) { $headers[] = 'Cookie: ' . implode('; ', array_map(static fn (string $name, string $value): string => $name . '=' . $value, array_keys($cookies), array_values($cookies))); }
        if ($payload !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR)); }
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$cookies, &$responseHeaders): int {
                if (str_contains($header, ':')) { [$name, $value] = explode(':', $header, 2); $responseHeaders[strtolower($name)][] = trim($value); }
                if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) {
                    if ($match[2] === '' || str_contains(strtolower($header), 'max-age=0')) { unset($cookies[$match[1]]); } else { $cookies[$match[1]] = $match[2]; }
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        return [$status, is_string($body) ? (json_decode($body, true) ?? []) : [], $responseHeaders, $body];
    };
    $guest = [];
    for ($attempt = 0; $attempt < 50; $attempt++) { [$status, $identity] = $request('/api/_identity', $guest); if ($status === 200) { break; } usleep(100_000); }
    $assert($status === 200 && $identity['data']['id'] === null, 'Isolated HTTP identity starts anonymous');
    $assert($identity['data']['product_loaded'] === [] && $identity['data']['ready'], 'HTTP identity works without Users, social domains or Settings');
    $csrf = $identity['data']['csrf'];
    [$status] = $request('/api/_user', $guest); $assert($status === 401, 'Anonymous protected resource returns 401');
    [$status] = $request('/api/_access', $guest); $assert($status === 401, 'Accounts access guard preserves JSON authentication behavior');
    [$status, , $headers] = $request('/_web', $guest, accept: 'text/html');
    $assert(in_array($status, [302, 303], true) && str_contains($headers['location'][0] ?? '', '/login?next='), 'Web access guard preserves the login return URL');
    [$status] = $request('/api/_sessions', $guest, 'POST', ['username' => 'Admin', 'password' => $password]);
    $assert($status === 403, 'Login still requires shared CSRF');
    [$status] = $request('/api/_sessions', $guest, 'POST', ['username' => 'Admin', 'password' => 'wrong'], $csrf);
    $assert($status === 401, 'Wrong password is rejected');
    foreach (['Bot', 'Banned', 'Waiting', 'Unknown'] as $username) {
        [$status] = $request('/api/_sessions', $guest, 'POST', ['username' => $username, 'password' => $password], $csrf);
        $assert($status === 401, 'Inactive or noninteractive account cannot log in: ' . $username);
    }
    $sessionName = array_key_first($guest); $before = $guest[$sessionName];
    [$status, $login, $headers] = $request('/api/_sessions', $guest, 'POST', ['username' => 'ADMIN@EXAMPLE.TEST', 'password' => $password, 'remember' => true], $csrf);
    $assert($status === 200 && $login['data']['id'] === 1, 'Email login remains case insensitive');
    $assert($guest[$sessionName] !== $before, 'Login rotates the session identifier');
    $remember = $guest['tinycat_remember'];
    $cookieHeaders = strtolower(implode('\n', $headers['set-cookie'] ?? []));
    $assert(str_contains($cookieHeaders, 'httponly') && str_contains($cookieHeaders, 'samesite=lax'), 'Remember cookie retains shared security attributes');
    [$status, $identity] = $request('/api/_identity', $guest);
    $assert($status === 200 && $identity['data']['id'] === 1 && $identity['data']['product_loaded'] === [], 'Session restoration does not load social code');
    [$status] = $request('/api/_admin', $guest); $assert($status === 200, 'Administrator middleware accepts the account identity');
    [$status] = $request('/api/_access', $guest); $assert($status === 200, 'Canonical Accounts admin guard accepts the same identity');
    $rememberOnly = ['tinycat_remember' => $remember];
    [$status, $identity] = $request('/api/_identity', $rememberOnly);
    $assert($status === 200 && $identity['data']['id'] === 1, 'Remember-only request restores the account');
    $assert(isset($rememberOnly[$sessionName]), 'Remember restoration establishes a session');
    $tampered = ['tinycat_remember' => 'broken-' . $remember];
    [, $identity] = $request('/api/_identity', $tampered);
    $assert($identity['data']['id'] === null && !isset($tampered['tinycat_remember']), 'Invalid remember cookie is rejected and cleared');
    $legacyCookie = static function (int $id, int $expires, string $passwordHash): string {
        $mac = hash_hmac('sha256', $id . '|' . $expires, hash('sha256', 'TinyCat|' . $passwordHash, true));
        return rtrim(strtr(base64_encode(json_encode(['id' => (string) $id, 'expires' => $expires, 'mac' => $mac], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    };
    $legacy = ['tinycat_remember' => $legacyCookie(1, time() + 3600, $hash)];
    [, $identity] = $request('/api/_identity', $legacy);
    $assert($identity['data']['id'] === 1, 'Existing remember-cookie format survives the owner change');
    $expired = ['tinycat_remember' => $legacyCookie(1, time() - 3600, $hash)];
    [, $identity] = $request('/api/_identity', $expired);
    $assert($identity['data']['id'] === null, 'Expired remember credentials are rejected');
    $pdo->prepare('UPDATE users SET password = ? WHERE id = 1')->execute([Authentication::authPassword('Changed-fixture-43')]);
    $revoked = ['tinycat_remember' => $remember];
    [, $identity] = $request('/api/_identity', $revoked);
    $assert($identity['data']['id'] === null, 'Password change revokes previous remember credentials');
    $pdo->prepare('UPDATE users SET password = ? WHERE id = 1')->execute([$hash]);
    $pdo->exec("UPDATE users SET status = 'ban' WHERE id = 1");
    [, $identity] = $request('/api/_identity', $guest);
    $assert($identity['data']['id'] === null, 'Ban revokes an existing session on the next request');
    $pdo->exec("UPDATE users SET status = 'active' WHERE id = 1");
    $member = [];
    [, $identity] = $request('/api/_identity', $member); $memberCsrf = $identity['data']['csrf'];
    [$status, $login] = $request('/api/_sessions', $member, 'POST', ['username' => 'mEmBeR', 'password' => $password], $memberCsrf);
    $assert($status === 200 && $login['data']['id'] === 2, 'Username login remains case insensitive');
    [$status] = $request('/api/_user', $member); $assert($status === 200, 'Member identity authorizes user resources');
    [$status] = $request('/api/_admin', $member); $assert($status === 403, 'Member cannot access administrator resources');
    [$status] = $request('/api/_access', $member); $assert($status === 403, 'Accounts admin guard preserves authorization');
    [$status] = $request('/api/_logout', $member, 'POST', [], $login['data']['csrf']); $assert($status === 200, 'Logout uses the shared CSRF transport');
    [, $identity] = $request('/api/_identity', $member); $assert($identity['data']['id'] === null, 'Logout clears the identity');
    Authentication::reset(['id' => 9, 'role' => 'admin'], true);
    $assert(TinyCat\Core\Identity\Authentication::auth('id') === 9, 'Legacy accessor observes canonical identity state');
    TinyCat\Core\Identity\Authentication::reset(['id' => 10, 'role' => 'user'], true);
    $assert(Authentication::auth('id') === 10 && !TinyCat\Core\Identity\Authentication::authIs('admin'), 'Legacy reset updates canonical state');
    $assert(TinyCat\Social\Profiles\Roles::rolesWith('allows_login') === Roles::rolesWith('allows_login'), 'Legacy role metadata delegates account policy');
    $assert(TinyCat\Social\Profiles\Roles::receivesNotifications('admin') && !TinyCat\Social\Profiles\Roles::receivesNotifications('bot'), 'Social role capabilities retain their own policy');
    $assert(TinyCat\Social\Profiles\Profiles::app_auth_account_ready() === Readiness::ready(), 'Legacy readiness delegates to Accounts');
    $assert(array_column($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_ASSOC), 'name') === ['users'], 'No social or settings schema was created');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    Connection::set(new PDO('sqlite::memory:')); unset($insert); $pdo = null;
    $resolved = realpath($directory); $storage = realpath($root . '/storage');
    if ($resolved !== false && $storage !== false && str_starts_with($resolved, $storage . DIRECTORY_SEPARATOR . 'account-identity-')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
        }
        rmdir($resolved);
    }
}
echo "PASS standalone account identity, real sessions, remember credentials and compatibility ({$checks} checks)\n";
