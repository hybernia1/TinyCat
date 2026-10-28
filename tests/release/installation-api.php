<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
$workspace = dirname(__DIR__, 2);
if (!is_file($workspace . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP installation API: local MySQL configuration unavailable.\n";
    exit(0);
}
$local = require $workspace . '/config.php';
$db = $local['database'];
if (($db['driver'] ?? 'mysql') !== 'mysql' || empty($db['host']) || !array_key_exists('user', $db)) {
    echo "SKIP installation API: local MySQL configuration unavailable.\n";
    exit(0);
}
$configHash = hash_file('sha256', $workspace . '/config.php');
$name = 'tinycat_install_api_' . bin2hex(random_bytes(6));
$root = $workspace . '/storage/install-api-' . bin2hex(random_bytes(6));
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$serverDb = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';charset=utf8mb4', $db['user'], $db['password'], $options);
$http = null;
$created = false;
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException($message . ' Last response: ' . ($GLOBALS['install_api_last_response'] ?? '')); }
};
try {
    // Only the isolated copy may receive config.php or installation writes.
    mkdir($root, 0775, true);
    foreach (['App', 'assets', 'tools', 'lang', 'migrations'] as $directory) {
        $source = $workspace . '/' . $directory;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            if ($directory === 'assets' && preg_match('~^build(?:[/\\\\]|$)~', $relative)) { continue; }
            $target = $root . '/' . $directory . '/' . $relative;
            if ($file->isDir()) { if (!is_dir($target)) { mkdir($target, 0775, true); } }
            else { if (!is_dir(dirname($target))) { mkdir(dirname($target), 0775, true); } copy($file->getPathname(), $target); }
        }
    }
    copy($workspace . '/index.php', $root . '/index.php');
    $serverDb->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $http = proc_open([PHP_BINARY, '-S', $address, '-t', $root, $root . '/index.php'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root);
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if (is_resource($probe)) { fclose($probe); break; }
        usleep(50_000);
    }
    $cookie = '';
    $csrf = '';
    $request = static function (string $method, string $path, ?array $body = null, bool $token = true) use ($address, &$cookie, &$csrf): array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($token && $csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
        $text = file_get_contents('http://' . $address . $path, false, $context);
        $received = http_get_last_response_headers() ?? [];
        foreach ($received as $header) {
            if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $header, $match)) { $cookie = $match[1]; }
        }
        $GLOBALS['install_api_last_response'] = substr((string) $text, 0, 1500);
        preg_match('/\s(\d{3})\s/', $received[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), json_decode((string) $text, true), (string) $text];
    };
    [$status, , $html] = $request('GET', '/install');
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $match);
    $csrf = $match[1] ?? '';
    $assert($status === 200 && $csrf !== '' && str_contains($html, 'action="/api/installation/steps/language"'), 'An installation without config or database renders an API-backed form.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'en'], false);
    $assert($status === 403 && $result['code'] === 'csrf_token_mismatch', 'Installation writes require the shared CSRF policy.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'invalid']);
    $assert($status === 422 && !$result['ok'], 'Invalid installation input uses the common error envelope.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'cs']);
    $assert($status === 200 && $result['data']['step'] === 'db' && $result['data']['redirect'] === '/install?step=db', 'JSON input advances the language step.');
    $input = ['host' => $db['host'], 'port' => (int) ($db['port'] ?? 3306), 'name' => $name, 'user' => $db['user'], 'password' => $db['password'], 'charset' => 'utf8mb4'];
    [$status, $result, $text] = $request('POST', '/api/installation/steps/db', $input);
    $assert($status === 200 && $result['data']['step'] === 'tables' && !array_key_exists('database', $result['data']), 'Database setup returns step state without exposing credentials.');
    [$status, $result] = $request('POST', '/api/installation/steps/tables', []);
    $assert($status === 200 && $result['data']['step'] === 'admin', 'The tables step creates the isolated schema through the API.');
    [$status, $result] = $request('POST', '/api/installation/steps/tables', []);
    $assert($status === 200, 'The tables step can safely be retried.');
    $password = 'Installer-API-Regression-42';
    [$status, $result] = $request('POST', '/api/installation/steps/admin', ['username' => 'install_api_admin', 'password' => $password, 'password_confirm' => 'different']);
    $assert($status === 422, 'Invalid administrator data fails without completing installation.');
    [$status, $result] = $request('POST', '/api/installation/steps/admin', ['username' => 'install_api_admin', 'password' => $password, 'password_confirm' => $password]);
    $assert($status === 200 && $result['data']['step'] === 'done', 'The final API step creates the administrator and configuration.');
    $installed = require $root . '/config.php';
    $assert($installed['install']['complete'] === true && $installed['install']['locale'] === 'cs' && $installed['database']['name'] === $name, 'Only the isolated configuration is written.');
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $name, $db['user'], $db['password'], $options);
    $row = $pdo->query("SELECT role, password FROM users WHERE username = 'install_api_admin'")->fetch();
    $assert($row['role'] === 'admin' && password_verify($password, $row['password']), 'The isolated administrator has the correct role and a verified password hash.');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'cs']);
    $assert($status === 409 && $result['code'] === 'installation_locked', 'Completed installations reject further setup writes.');
    $installed['install']['complete'] = false;
    file_put_contents($root . '/config.php', "<?php\nreturn " . var_export($installed, true) . ";\n");
    $request('GET', '/install');
    [$status, $result] = $request('POST', '/api/installation/steps/language', ['locale' => 'en']);
    $assert($status === 409 && $result['code'] === 'installation_locked', 'An existing initialized database stays locked even if the config flag is missing and the wizard was visited.');
    [$status] = $request('POST', '/install', []);
    $assert($status === 405, 'The installer page has no second write transport.');
    $assert(hash_file('sha256', $workspace . '/config.php') === $configHash, 'The real workspace configuration remains byte-for-byte unchanged.');
    echo "PASS complete installation API ({$checks} checks, isolated copy and database)\n";
} finally {
    if (is_resource($http)) { proc_terminate($http); proc_close($http); }
    foreach ($pipes ?? [] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    $pdo = null;
    if ($created) { $serverDb->exec('DROP DATABASE `' . $name . '`'); }
    if (is_dir($root) && str_starts_with($root, $workspace . '/storage/install-api-')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($root);
    }
}
