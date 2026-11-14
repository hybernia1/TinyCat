<?php
declare(strict_types=1);

// Exercise the selected module, its real routes and build in a physical product copy.
$sourceRoot = dirname(__DIR__, 3);
if (($argv[1] ?? '') !== '--isolated') {
    $workspace = sys_get_temp_dir() . '/tinycat-account-module-' . bin2hex(random_bytes(10));
    $excluded = ['App/Modules/Social', 'App/Modules/Blog', 'App/Core/Support/compiled', 'assets/build'];
    $copy = static function (string $relative) use (&$copy, $sourceRoot, $workspace, $excluded): void {
        if (in_array($relative, $excluded, true)) { return; }
        $source = $sourceRoot . '/' . $relative; $target = $workspace . '/' . $relative;
        if (is_file($source)) { copy($source, $target); return; }
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (scandir($source) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($relative . '/' . $entry); } }
    };
    try {
        foreach (['App', 'assets', 'lang', 'tools'] as $directory) { $copy($directory); }
        file_put_contents($workspace . '/App/modules.php', "<?php\nreturn [];\n");
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($workspace), $exitCode);
    } finally {
        $resolved = realpath($workspace); $temporary = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'tinycat-account-module-')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); } else { rmdir($entry->getPathname()); }
            }
            rmdir($resolved);
        }
    }
    exit($exitCode);
}

define('TINYCAT', true);
$workspace = $argv[2];
require $workspace . '/App/autoload.php';
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
foreach (['Users', 'Status', 'Bots'] as $domain) { $assert(!is_dir($workspace . '/App/Modules/' . $domain) && !is_dir($workspace . '/App/Modules/' . $domain . '/assets'), 'Excluded physical domain: ' . $domain); }
$modules = TinyCat\Core\Modules\Loader::load($workspace);
TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(locale: static fn (): string => 'en'), $modules);
$assert(TinyCat\Core\Identity\ServiceProvider::tables() === ['users', 'password_reset_tokens'], 'Accounts owns only account tables');
$assert(array_keys(TinyCat\Core\Identity\Participants::current()) === ['tinycat.accounts'], 'Only selected lifecycle providers are resolved');
$assert(TinyCat\Core\Modules\Registrations::current()->owner('maintenance', 'orphan_avatar_files') === 'tinycat.accounts', 'Stable avatar maintenance has its new owner');
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
TinyCat\Database\Connection::set($pdo);
$assert(!TinyCat\Core\Identity\Readiness::ready(), 'Missing account schema is unready without DDL at startup');
TinyCat\Core\Identity\Schema::install($pdo); TinyCat\Core\Security\Schema::install($pdo);
TinyCat\Core\Identity\Schema::install($pdo); // Fresh install is safe to repeat.
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$assert($tables === ['ip_action_limits', 'password_reset_tokens', 'users'], 'Fresh assembly creates no social, bot or content tables');
$pdo = new PDO('sqlite:' . $workspace . '/accounts.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
TinyCat\Database\Connection::set($pdo);
TinyCat\Database\ModuleSchemas::install($pdo, TinyCat\Core\Modules\Distribution::definitions($modules, false));
$insert = $pdo->prepare('INSERT INTO users(username, email, password, role, locale) VALUES (?, ?, ?, ?, ?)');
foreach ([['Admin', 'admin@example.test', 'admin'], ['Member', 'member@example.test', 'user']] as [$name, $email, $role]) { $insert->execute([$name, $email, password_hash('Secret-123456', PASSWORD_DEFAULT), $role, 'en']); }
$insert = null;
$assert(TinyCat\Core\Identity\Readiness::ready(), 'Installed assembly is ready after an account with a password exists');
$compile = require $workspace . '/tools/assets/compile-assets.php';
$files = $compile();
// A framework-only selection still includes identity and security without publication storage.
$technical = TinyCat\Core\Modules\Loader::fromSelection($workspace, []);
$assert($technical->catalog->has('tinycat.accounts') && $technical->catalog->has('tinycat.scheduler') && !$technical->catalog->has('tinycat.social'), 'Mandatory framework is independent of functional modules');
$manifest = json_decode($files['assets/build/manifest.json'], true, 512, JSON_THROW_ON_ERROR);
$assert(count($manifest['clients']) >= 3, 'Selected module builds public, admin and install scopes');
foreach (TinyCat\Support\ClientAssets::scopes() as $scope) {
    $sources = TinyCat\Support\AssetGraph::order(TinyCat\Support\ClientAssets::entries($scope));
    $assert(!preg_match('~/modules/(?:users|status|bots)/~', implode("\n", $sources)), 'Selected graph has no social imports: ' . $scope);
    foreach (array_keys(TinyCat\Support\TranslationSources::packages()) as $locale) { $assert(TinyCat\Support\ClientAssets::catalog($scope, $locale) !== [], 'Selected client catalog: ' . $scope . '/' . $locale); }
}
$definitions = TinyCat\Support\ClientAssets::definitions('/admin/users');
$assert(isset($definitions['users/admin-fields'], $definitions['users/auth-content']) && !isset($definitions['users/following-profile']), 'Accounts builds shared account components without following UI');
foreach ($files as $path => $content) { $target = $workspace . '/' . $path; if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); } file_put_contents($target, $content); }
mkdir($workspace . '/sessions', 0700);
$server = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) { throw new RuntimeException('Cannot allocate Accounts fixture port.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $workspace . '/sessions', '-S', $address, $sourceRoot . '/tests/fixtures/accounts/module-worker.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace . '/server.log', 'a'], 2 => ['file', $workspace . '/server.log', 'a']], $pipes, $workspace, array_merge(getenv(), ['TINYCAT_ACCOUNTS_WORKSPACE' => $workspace]));
    if (!is_resource($server)) { throw new RuntimeException('Cannot start Accounts HTTP fixture.'); }
    fclose($pipes[0]); $cookies = []; $csrf = '';
    $request = static function (string $path, string $method = 'GET', ?array $payload = null, string $actor = '', bool $withCsrf = true) use ($address, &$cookies, &$csrf): array {
        $handle = curl_init('http://' . $address . $path); $headers = [];
        if ($actor !== '') { $headers[] = 'Authorization: Bearer ' . $actor; }
        if ($withCsrf && $csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
        if ($cookies !== []) { $headers[] = 'Cookie: ' . implode('; ', $cookies); }
        if ($payload !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload)); }
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$cookies): int {
                if (preg_match('/^Set-Cookie:\s*([^=]+=[^;]*)/i', $header, $match)) { $cookies[explode('=', $match[1], 2)[0]] = $match[1]; }
                return strlen($header);
            }]);
        $body = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        return [$status, is_string($body) ? (json_decode($body, true) ?? []) : [], (string) $body];
    };
    for ($attempt = 0; $attempt < 50; $attempt++) { [$status, $data] = $request('/api/_accounts'); if ($status === 200) { break; } usleep(100_000); }
    $assert($status === 200, 'Selected Accounts module starts over HTTP'); $csrf = $data['data']['csrf'];
    foreach (['/login', '/register', '/recovery'] as $path) {
        [$status, , $html] = $request($path);
        $assert($status === 200 && str_contains($html, '<form') && str_contains($html, 'csrf-token') && str_contains($html, 'assets/build/'), 'Real HTML and compiled assets: ' . $path);
    }
    preg_match_all('~(?:src|href)="(/assets/build/[^\"]+)"~', $html, $urls);
    foreach ($urls[1] as $url) { [$status, , $body] = $request($url); $assert($status === 200 && $body !== '', 'Compiled asset is served: ' . $url); }
    [$status] = $request('/api/users/me'); $assert($status === 401, 'Private resources require identity');
    [$status] = $request('/api/users', actor: 'member'); $assert($status === 403, 'Member cannot enumerate administrative accounts');
    [$status, $data] = $request('/api/users/authentication'); $assert($status === 200 && $data['data']['registration_enabled'], 'Authentication policy comes from assembly configuration');
    [$status, $data] = $request('/api/users/me', actor: 'member');
    $assert($status === 200 && $data['data']['user']['username'] === 'Member' && !isset($data['data']['user']['url'], $data['data']['user']['password']), 'Canonical management resource has no invented author URL or password');
    [$status] = $request('/api/users/me', 'PATCH', ['bio' => 'Blocked'], 'member', false); $assert($status === 403, 'Account writes require CSRF');
    [$status, $data] = $request('/api/users/me', 'PATCH', ['bio' => 'Standalone', 'locale' => 'en', 'theme' => 'dark'], 'member');
    $assert($status === 200 && $data['data']['user']['bio'] === 'Standalone', 'Profile update works through registered route');
    [$status, $data] = $request('/api/users', actor: 'admin'); $assert($status === 200 && count($data['data']['items']) === 2, 'Administrative read model works without social tables');
    [$status, , $html] = $request('/admin/users', actor: 'admin'); $assert($status === 200 && str_contains($html, 'users-list'), 'Administrative HTML shares registered components');
    [$status, $data] = $request('/api/users/registrations', 'POST', ['username' => 'NewMember', 'email' => 'new@example.test', 'password' => 'Another-123456', 'password_confirm' => 'Another-123456', 'platform_terms' => '1']);
    $assert($status === 201 && $data['data']['status'] === 'active' && $data['data']['redirect'] === '/', 'Registration uses generic account landing');
    [$status] = $request('/api/users/password-resets', 'POST', ['email' => 'member@example.test']);
    $assert($status === 201 && is_file($workspace . '/delivered-token'), 'Recovery uses explicitly configured delivery');
    [$status] = $request('/api/users/password-resets/confirm', 'POST', ['token' => file_get_contents($workspace . '/delivered-token'), 'password' => 'Changed-123456', 'password_confirm' => 'Changed-123456']);
    $assert($status === 200 && password_verify('Changed-123456', TinyCat\Database\Connection::value('SELECT password FROM users WHERE id = 2')), 'Recovery confirms through real HTTP adapter');
    [$status] = $request('/api/users/sessions', 'POST', ['username' => 'Member', 'password' => 'wrong']); $assert($status === 422, 'Login rejects invalid credentials');
    [$status, $data] = $request('/api/users/sessions', 'POST', ['username' => 'Member', 'password' => 'Changed-123456']);
    $assert($status === 200 && $data['data']['user']['id'] === 2 && $data['data']['redirect'] === '/', 'Login uses account identity and generic landing');
    // Use the same physical copy (without Social) to exercise shared inbox/health services.
    \TinyCat\Core\Notifications\Schema::install($pdo);
    $foreignTables = array_column($pdo->query('PRAGMA foreign_key_list(notifications)')->fetchAll(PDO::FETCH_ASSOC), 'table');
    $assert(array_diff($foreignTables, ['users']) === [], 'Fresh inbox has no foreign keys to publication or social tables');
    $savedRegistries = \TinyCat\Core\Modules\Registrations::current();
    $orderType = new \TinyCat\Core\Modules\NotificationType('fixture.order', static fn (array $rows): array => $rows, static fn (array $row): string => '/orders/' . (int) json_decode((string) $row['payload_json'], true)['order_id']);
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries(['fixture.platform' => new \TinyCat\Core\Modules\Contributions(notificationTypes: [$orderType, ...\TinyCat\Core\Health\Alerts::types()])]));
    $notice = \TinyCat\Core\Notifications\Delivery::record(1, 'fixture.order', 'order:42', payload: ['order_id' => 42]);
    $assert($notice['created'] && \TinyCat\Core\Notifications\Inbox::open($notice['id'], 1) === '/orders/42', 'Opaque non-publication subjects work without Social');
    $duplicate = \TinyCat\Core\Notifications\Delivery::record(1, 'fixture.order', 'order:42', payload: ['order_id' => 42], refresh: false);
    $assert(!$duplicate['created'] && \TinyCat\Core\Notifications\Inbox::unreadCount(1) === 0, 'Idempotent system delivery preserves read state');
    $assert(\TinyCat\Core\Notifications\Inbox::open($notice['id'], 2) === '/notifications', 'Another account cannot open the notification');
    \TinyCat\Core\Settings\Store::reset();
    $inspection = \TinyCat\Core\Health\Service::run(['mode' => 'admin', 'scheduler_run_id' => 42]);
    $assert($inspection['ok'] && $inspection['changed'] === 1, 'Health delivers to the active administrator without Social or fake actors');
    $assert(\TinyCat\Core\Notifications\Inbox::items(1)[0]['actor_id'] === null, 'Inbox lists actorless system notices without social tables');
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries());
    $assert(\TinyCat\Core\Notifications\Inbox::open($notice['id'], 1) === '/notifications', 'Inactive subject owner falls back safely');
    \TinyCat\Core\Modules\Registrations::configure($savedRegistries);

    $legacy = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $legacy->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY, notification_key TEXT, read_at TEXT, created_at TEXT, updated_at TEXT)');
    $legacy->exec("INSERT INTO notifications VALUES (77, 'legacy:key', '2026-01-01', '2025-12-01', '2026-01-01')");
    $before = $legacy->query('SELECT * FROM notifications')->fetch();
    $upgrade = require $sourceRoot . '/migrations/20261112_001_notification_payload.php';
    $upgrade($legacy); $upgrade($legacy);
    $after = $legacy->query('SELECT * FROM notifications')->fetch();
    unset($after['payload_json']);
    $assert($after === $before, 'Inbox migration is idempotent and preserves IDs, keys, read state and timestamps');

    [$status] = $request('/api/users/1', 'DELETE', [], 'admin'); $assert($status === 409, 'Primary administrator remains protected');
    [$status] = $request('/api/users/2', 'DELETE', [], 'admin'); $assert($status === 200, 'Selected account lifecycle supports deletion without social tables');
    $assert((int) TinyCat\Database\Connection::value('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = 2') === 0, 'Recovery records follow account deletion');
    [$status] = $request('/api/users/2/following', actor: 'member'); $assert($status === 404, 'Social endpoints are absent');
    [$status, , $svg] = $request('/avatar/generated/v1/2.svg'); $assert($status === 200 && str_starts_with($svg, '<svg'), 'Avatar route is owned by Accounts');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    TinyCat\Database\Connection::set(new PDO('sqlite::memory:')); $pdo = null;
}
$assert(!is_file($workspace . '/product-loads.log'), 'All HTTP scenarios avoid loading product classes');
foreach (get_declared_classes() as $class) { $assert(!preg_match('/^TinyCat\\\\(?:Users|Status|Bots|Settings|Site|Moderation|Notifications)\\\\/', $class), 'Build avoids loading ' . $class); }
echo "PASS standalone Accounts module, schema, routes, HTML and build ({$checks} checks)\n";
