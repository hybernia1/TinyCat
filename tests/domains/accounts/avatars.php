<?php
declare(strict_types=1);

// Prove physical independence as well as class independence, without touching local uploads.
$sourceRoot = dirname(__DIR__, 3);
if (($argv[1] ?? '') !== '--isolated') {
    $workspace = sys_get_temp_dir() . '/tinycat-account-avatars-' . bin2hex(random_bytes(10));
    $copy = static function (string $source, string $target) use (&$copy, $sourceRoot): void {
        if ($source === $sourceRoot . '/App/Modules/Social') { return; }
        if (is_file($source)) { copy($source, $target); return; }
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (scandir($source) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($source . '/' . $entry, $target . '/' . $entry); } }
    };
    try {
        $copy($sourceRoot . '/App', $workspace . '/App');
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($workspace), $exitCode);
    } finally {
        $resolved = realpath($workspace); $temporary = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-account-avatars-')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
            }
            rmdir($resolved);
        }
    }
    exit($exitCode);
}

use TinyCat\Core\Identity\Avatar;
use TinyCat\Core\Identity\AvatarHttp;
use TinyCat\Core\Identity\Avatars;
use TinyCat\Core\Identity\GeneratedAvatar;
use TinyCat\Core\Identity\Resource;
use TinyCat\Database\Connection;

define('TINYCAT', true);
$workspace = $argv[2];
require $workspace . '/App/autoload.php';
TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(locale: static fn (): string => 'en'));
TinyCat\Core\Modules\Resources::configure(TinyCat\Core\Modules\Loader::fromSelection($workspace, [
])->catalog);
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
$assert(!is_dir($workspace . '/App/Modules/Social'), 'The test assembly has no Users source directory');
$pdo = new PDO('sqlite:' . $workspace . '/avatar.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Connection::set($pdo);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password TEXT, role TEXT, status TEXT, bio TEXT, locale TEXT, theme TEXT, email_notifications INTEGER, avatar_exists INTEGER, avatar_appearance TEXT, updated_at TEXT)");
$pdo->exec("INSERT INTO users VALUES (1, 'Member', 'private@example.test', 'private-hash', 'user', 'active', 'Bio', 'en', 'dark', 1, 0, NULL, '2026-11-11 00:00:00')");
$pdo->exec('CREATE TABLE ip_action_limits (ip_address TEXT, action_name TEXT, bucket_start TEXT, action_count INTEGER, updated_at TEXT, UNIQUE(ip_address, action_name, bucket_start))');
$user = Connection::find('users', ['id' => 1]);
$public = Resource::profile($user); $private = Resource::privateProfile($user); $identity = Resource::identity($user);
$assert(!isset($public['url'], $private['url']) && !str_contains(json_encode($public), '/author/'), 'Account resources do not invent a social author URL');
foreach (['email', 'password', 'role', 'status', 'email_notifications', 'locale', 'theme'] as $key) { $assert(!array_key_exists($key, $public), 'Public profile excludes ' . $key); }
$assert($private['email'] === 'private@example.test' && $private['theme'] === 'dark' && !isset($private['password']), 'Private profile is explicit and still excludes credentials');
$assert($identity['role'] === 'user' && !isset($identity['email'], $identity['password']), 'Session identity excludes private credentials and email');
$assert(Resource::profile($user, '/people/1')['url'] === '/people/1' && Resource::identity(null) === null, 'The owning application supplies public links and missing identity remains null');
$assert(Resource::creation()['options']['roles'] === ['admin', 'user'] && Resource::creation()['options']['statuses'] === ['active', 'waiting', 'ban'], 'Creation options use canonical account policy');
$auth = Resource::authentication('register', '/next', '/login?next=x', '/api/auth-switch', true, ['enabled' => false]);
$assert($auth['form_url'] === '/api/users/registrations' && $auth['next'] === '/next' && $auth['registration_enabled'], 'Authentication resource uses explicit transport policy');
$listing = Resource::listing(['items' => [], 'roles' => ['admin' => 'Administrator'], 'statuses' => ['active' => 'Active'], 'filters' => []], ['page' => 1, 'last_page' => 1, 'total' => 0]);
$assert($listing['roles'] === ['admin'] && $listing['statuses'] === ['active'] && !isset($listing['navigation']['target']), 'Administrative list metadata preserves its JSON shape');
$appearance = GeneratedAvatar::appearance('{"fur":"ginger","gender":"male","unknown":"value"}');
$assert($appearance['fur'] === 'ginger' && !isset($appearance['unknown']), 'Appearance options use the single account source');
$assert(GeneratedAvatar::url(0) === '' && Avatar::url(0, false) === '', 'Invalid avatar identities remain empty');
$assert(GeneratedAvatar::svg(1) === GeneratedAvatar::svg(1) && GeneratedAvatar::svg(1) !== GeneratedAvatar::svg(2), 'Generated avatars remain deterministic per identity');
$assert(str_contains(Avatars::user_avatar_url(['author_id' => 7, 'author_avatar_exists' => 0]), '/7.svg'), 'Joined author rows use the common avatar serializer');
$assert(Avatars::user_avatar_url(null) === '' && AvatarHttp::resolve('missing') === null && AvatarHttp::resolve('123') === null, 'Missing and invalid lookups have no fallback dependency');
mkdir($workspace . '/sessions', 0700); mkdir($workspace . '/uploads/avatars', 0700, true);
$server = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) { throw new RuntimeException('Cannot allocate avatar server port.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $workspace . '/sessions', '-S', $address, $sourceRoot . '/tests/fixtures/accounts/avatar-worker.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace . '/server.log', 'a'], 2 => ['file', $workspace . '/server.log', 'a']], $pipes, $workspace, array_merge(getenv(), ['TINYCAT_AVATAR_WORKSPACE' => $workspace]));
    if (!is_resource($server)) { throw new RuntimeException('Cannot start avatar server.'); }
    fclose($pipes[0]);
    $cookies = [];
    $request = static function (string $path, string $method = 'GET', array|string|null $payload = null, array $extra = [], bool $authorized = true) use ($address, &$cookies): array {
        $handle = curl_init('http://' . $address . $path); $responseHeaders = []; $headers = ['Accept: application/json'];
        if ($authorized) { $headers[] = 'Authorization: Bearer avatar-user'; }
        if ($cookies !== []) { $headers[] = 'Cookie: ' . implode('; ', $cookies); }
        foreach ($extra as $key => $value) { $headers[] = $key . ': ' . $value; }
        if (is_array($payload)) { $headers[] = 'Content-Type: application/json'; $payload = json_encode($payload, JSON_THROW_ON_ERROR); }
        if ($payload !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, $payload); }
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders, &$cookies): int {
                if (str_contains($header, ':')) { [$name, $value] = explode(':', $header, 2); $responseHeaders[strtolower($name)][] = trim($value); }
                if (preg_match('/^Set-Cookie:\s*([^=]+=[^;]*)/i', $header, $match)) { $cookies[explode('=', $match[1], 2)[0]] = $match[1]; }
                return strlen($header);
            }]);
        $body = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        return [$status, is_string($body) ? (json_decode($body, true) ?? []) : [], $responseHeaders, $body];
    };
    for ($attempt = 0; $attempt < 50; $attempt++) { [$status, $data] = $request('/api/_avatars'); if ($status === 200) { break; } usleep(100_000); }
    $assert($status === 200 && $data['data']['loaded_product'] === [], 'Isolated avatar HTTP starts without product classes');
    $csrf = $data['data']['csrf'];
    [$status] = $request('/api/_avatars', authorized: false); $assert($status === 401, 'Account resources still require server authorization');
    [$status, , $headers, $svg] = $request('/avatar/generated/v1/1.svg', authorized: false);
    $assert($status === 200 && str_starts_with($svg, '<svg') && str_contains($headers['content-type'][0], 'image/svg+xml'), 'Generated avatar is served without Users');
    $assert(!isset($headers['set-cookie']) && str_contains($headers['cache-control'][0], 'immutable'), 'Public generated avatar does not start a session');
    [$status] = $request('/avatar/generated/v1/1.svg', extra: ['If-None-Match' => $headers['etag'][0]], authorized: false); $assert($status === 304, 'Generated avatar preserves ETag handling');
    [$status] = $request('/avatar/generated/v1/1.svg?fur=invalid', authorized: false); $assert($status === 404, 'Invalid appearance parameters remain rejected');
    [$status, , $headers] = $request('/avatar/Member', authorized: false); $assert($status === 302 && str_contains($headers['location'][0], '/avatar/generated/v1/1.svg'), 'Username lookup preserves generated fallback URL');
    [$status] = $request('/avatar/missing', authorized: false); $assert($status === 404, 'Missing username uses the technical 404 response');
    [$status] = $request('/api/_avatars/appearance', 'PATCH', ['appearance' => ['fur' => 'ginger']]); $assert($status === 403, 'Appearance mutation retains common CSRF');
    [$status] = $request('/api/_avatars/appearance', 'PATCH', ['appearance' => ['fur' => 'ginger']], ['X-CSRF-Token' => $csrf]);
    $assert($status === 200 && str_contains(Connection::value('SELECT avatar_appearance FROM users WHERE id = 1'), 'ginger'), 'Appearance mutation persists without social services');
    $image = imagecreatetruecolor(12, 8); imagefill($image, 0, 0, imagecolorallocate($image, 120, 60, 20)); ob_start(); imagepng($image); $png = (string) ob_get_clean(); imagedestroy($image);
    $boundary = 'tinycat-avatar-' . bin2hex(random_bytes(8));
    $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"_method\"\r\n\r\nPUT\r\n--" . $boundary . "\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\nContent-Type: image/png\r\n\r\n" . $png . "\r\n--" . $boundary . "--\r\n";
    $uploadHeaders = ['Content-Type' => 'multipart/form-data; boundary=' . $boundary, 'X-CSRF-Token' => $csrf];
    [$status, $saved] = $request('/api/_avatars/avatar', 'POST', $body, $uploadHeaders);
    $file = $workspace . '/uploads/avatars/1.webp';
    $assert($status === 200 && $saved['data']['user']['avatar_custom'] && is_file($file), 'Real multipart upload uses the existing transactional storage');
    $assert(getimagesize($file)['mime'] === 'image/webp' && (int) Connection::value('SELECT avatar_exists FROM users WHERE id = 1') === 1, 'Upload persists WebP and account metadata');
    $assert((int) Connection::value("SELECT action_count FROM ip_action_limits WHERE action_name = 'upload'") === 1, 'Member upload still consumes the technical account limiter');
    $previousHash = hash_file('sha256', $file);
    $pdo->exec("CREATE TRIGGER reject_avatar BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'injected avatar SQL failure'); END");
    [$status] = $request('/api/_avatars/avatar', 'POST', $body, $uploadHeaders);
    $assert($status === 500 && hash_file('sha256', $file) === $previousHash && (int) Connection::value('SELECT avatar_exists FROM users WHERE id = 1') === 1, 'SQL failure restores the previous avatar and metadata');
    $pdo->exec('DROP TRIGGER reject_avatar');
    [$status, $data] = $request('/api/_avatars'); $assert($status === 200 && $data['data']['loaded_product'] === [], 'Upload and rate limiting did not load social or settings classes');
    [$status] = $request('/api/_avatars/avatar', 'DELETE', [], ['X-CSRF-Token' => $csrf]);
    clearstatcache(true, $file);
    $assert($status === 200 && !is_file($file) && (int) Connection::value('SELECT avatar_exists FROM users WHERE id = 1') === 0, 'Removal preserves generated identity and clears the physical upload');
    $assert((glob($workspace . '/uploads/avatars/.avatar-*') ?: []) === [], 'Successful and failed mutations leave no staged avatar files');
    foreach (['TinyCat\\Social\\Profiles\\Profiles', 'TinyCat\\Social\\Profiles\\Avatar', 'TinyCat\\Social\\Posts\\Registration', 'TinyCat\\Social\\Automation\\Registration', 'TinyCat\\Social\\Moderation\\Restrictions', 'TinyCat\\Core\\Settings\\Store', 'TinyCat\\Core\\Runtime\\Errors'] as $class) { $assert(!class_exists($class, false), 'Account resources did not load ' . $class); }
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    Connection::set(new PDO('sqlite::memory:')); $pdo = null;
}
echo "PASS account resources and real avatar HTTP without Users sources, upload and rollback ({$checks} checks)\n";
