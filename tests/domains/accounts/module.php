<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

// Exercise the selected module, its real routes and build in a physical product copy.
$sourceRoot = dirname(__DIR__, 3);
if (($argv[1] ?? '') !== '--isolated') {
    $workspace = sys_get_temp_dir() . '/tinycat-account-module-' . bin2hex(random_bytes(10));
    $excluded = ['App/Modules/Social', 'App/Core/Support/compiled', 'assets/build'];
    $copy = static function (string $relative) use (&$copy, $sourceRoot, $workspace, $excluded): void {
        if (in_array($relative, $excluded, true)) { return; }
        $source = $sourceRoot . '/' . $relative; $target = $workspace . '/' . $relative;
        if (is_file($source)) { copy($source, $target); return; }
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (scandir($source) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $copy($relative . '/' . $entry); } }
    };
    try {
        foreach (['App', 'assets', 'tools'] as $directory) { $copy($directory); }
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
require $workspace . '/App/Core/Runtime/autoload.php';
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { $checks++; if (!$value) { throw new RuntimeException($message); } };
foreach (['Users', 'Status', 'Bots'] as $domain) { $assert(!is_dir($workspace . '/App/Modules/' . $domain) && !is_dir($workspace . '/App/Modules/' . $domain . '/assets'), 'Excluded physical domain: ' . $domain); }
$modules = TinyCat\Core\Modules\Loader::load($workspace);
TinyCat\Core\Runtime\Application::boot(new TinyCat\Core\Runtime\Context(locale: static fn (): string => 'en'), $modules);
$assert(TinyCat\Core\Identity\ServiceProvider::schemas()['tinycat.accounts']->tables === ['users', 'password_reset_tokens'], 'Accounts owns only account tables');
$assert(array_keys(TinyCat\Core\Identity\Participants::current()) === ['tinycat.accounts'], 'Only selected lifecycle providers are resolved');
$assert(TinyCat\Core\Modules\Registrations::current()->owner('maintenance', 'orphan_avatar_files') === 'tinycat.accounts', 'Stable avatar maintenance has its new owner');
$pdo = \tinycat_test_database();

TinyCat\Database\Connection::set($pdo);
$assert(!TinyCat\Core\Identity\ServiceProvider::ready(), 'Missing account schema is unready without DDL at startup');
TinyCat\Core\Identity\Schema::install($pdo); TinyCat\Core\Security\Schema::install($pdo);
TinyCat\Core\Identity\Schema::install($pdo); // Fresh install is safe to repeat.
$tables = $pdo->query("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$assert($tables === ['ip_action_limits', 'password_reset_tokens', 'users'], 'Fresh assembly creates no social, bot or content tables');
$pdo = \tinycat_test_database($workspace . '/accounts.database.json');

TinyCat\Database\Connection::set($pdo);
TinyCat\Database\ModuleSchemas::install($pdo, TinyCat\Core\Modules\Distribution::definitions($modules));
$insert = $pdo->prepare('INSERT INTO users(username, email, password, role, locale) VALUES (?, ?, ?, ?, ?)');
foreach ([['Admin', 'admin@example.test', 'admin'], ['Member', 'member@example.test', 'user']] as [$name, $email, $role]) { $insert->execute([$name, $email, password_hash('Secret-123456', PASSWORD_DEFAULT), $role, 'en']); }
$insert = null;
$assert(TinyCat\Core\Identity\ServiceProvider::ready(), 'Installed assembly is ready after an account with a password exists');
$assert((int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0, 'Fresh settings fixture starts empty');
TinyCat\Core\Installation\Installer::default_settings(['locale' => 'en']);
$assert(TinyCat\Core\Settings\Store::setting('site.public_enabled') === false, 'Fresh core-only installation starts without a public website');
$pdo->exec('DELETE FROM settings');
TinyCat\Core\Settings\Store::reset();
TinyCat\Support\Cache::forget('core_autoload_settings');
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
$assert(array_filter(array_keys($definitions), static fn (string $name): bool => str_starts_with($name, 'publishing/')) === [], 'Core has no publication, comment or reaction component definitions');
$assert(!is_file($workspace . '/App/Core/Ui/DiscussionResource.php') && !is_dir($workspace . '/App/Core/Ui/assets/content'), 'Core distribution carries no Social formatter or publication assets');
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
    $request = static function (string $path, string $method = 'GET', ?array $payload = null, string $actor = '', bool $withCsrf = true, array $extraHeaders = []) use ($address, &$cookies, &$csrf): array {
        $handle = curl_init('http://' . $address . $path); $headers = $extraHeaders; $responseHeaders = [];
        if ($actor !== '') { $headers[] = 'Authorization: Bearer ' . $actor; }
        if ($withCsrf && $csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
        if ($cookies !== []) { $headers[] = 'Cookie: ' . implode('; ', $cookies); }
        if ($payload !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload)); }
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$cookies, &$responseHeaders): int {
                if (preg_match('/^Set-Cookie:\s*([^=]+=[^;]*)/i', $header, $match)) { $cookies[explode('=', $match[1], 2)[0]] = $match[1]; }
                if (str_contains($header, ':')) { [$key, $value] = explode(':', $header, 2); $responseHeaders[strtolower($key)] = trim($value); }
                return strlen($header);
            }]);
        $body = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        return [$status, is_string($body) ? (json_decode($body, true) ?? []) : [], (string) $body, $responseHeaders];
    };
    for ($attempt = 0; $attempt < 50; $attempt++) { [$status, $data] = $request('/api/_accounts'); if ($status === 200) { break; } usleep(100_000); }
    $assert($status === 200, 'Selected Accounts module starts over HTTP'); $csrf = $data['data']['csrf'];
    foreach ([400, 404, 500, 503] as $errorStatus) {
        [$status, , $errorHtml, $errorHeaders] = $request('/fixture-error/' . $errorStatus);
        $assert($status === $errorStatus && str_contains($errorHeaders['cache-control'] ?? '', 'no-store') && !str_contains($errorHtml, '/search/all') && str_contains($errorHtml, (string) $errorStatus), 'Core error actions and emergency fallback work without registered search: ' . $errorStatus);
    }
    [$status, , $privacy] = $request('/privacy');
    $assert($status === 200 && str_contains($privacy, 'Data and Account') && !str_contains($privacy, 'privacy.intro'), 'Core privacy renders its own account policy without a Social translation or section');
    [$status, , $llms, $llmsHeaders] = $request('/llms.txt');
    $assert($status === 200 && !str_contains($llms, '/author/') && !str_contains($llms, '/tag/') && !str_contains($llms, '## Social') && str_contains($llmsHeaders['cache-control'] ?? '', 'max-age=0, must-revalidate'), 'Core LLM document has no Social claims and revalidates after module changes');
    [$status, , $body] = $request('/llms.txt', extraHeaders: ['If-None-Match: ' . $llmsHeaders['etag']]);
    $assert($status === 304 && $body === '', 'Unchanged LLM documents retain conditional caching');
    foreach (['/sitemap.xml', '/sitemap-pages-1.xml', '/robots.txt', '/llms-full.txt'] as $path) {
        [$status, , $body, $headers] = $request($path);
        $assert($status === 200 && str_contains($headers['cache-control'] ?? '', 'max-age=0, must-revalidate') && !str_contains($body, '/author/') && !str_contains($body, '/tag/') && !str_contains($body, 'sitemap-status-'), 'Core discovery stays available without Social and revalidates: ' . $path);
        if ($path === '/sitemap-pages-1.xml') { $assert(str_contains($body, '/privacy</loc>'), 'Core owns its public information URLs'); }
    }
    foreach (['/sitemap-authors-1.xml', '/sitemap-tags-1.xml', '/sitemap-status-1.xml', '/author/2/feed', '/tag/example/feed', '/search/feed?q=example'] as $path) {
        [$status, , , $headers] = $request($path, extraHeaders: ['If-None-Match: "old-module-document"']);
        $assert($status === 404 && str_contains($headers['cache-control'] ?? '', 'no-store'), 'Unregistered owner routes cannot return cached module content: ' . $path);
    }
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
    [$status, , $body, $headers] = $request('/');
    $assert($status === 200 && !isset($headers['location']) && str_contains($body, 'name="username"'), 'Core-only homepage renders login directly for guests');
    [$status, , $body, $headers] = $request('/', actor: 'member');
    $assert($status === 200 && !isset($headers['location']) && str_contains($body, 'My account') && str_contains($body, '/api/users/2/management?panel=account') && !str_contains($body, 'name="password"'), 'Authenticated core-only homepage renders account actions without a redirect or eager editor');
    [$status, , , $headers] = $request('/', actor: 'admin');
    $assert($status === 302 && $headers['location'] === '/admin', 'Login homepage sends administrators to their normal landing');
    [$status, , , $headers] = $request('/?next=%2F', actor: 'admin', extraHeaders: ['Referer: http://' . $address . '/']);
    $assert($status === 302 && $headers['location'] === '/admin', 'A return to the login homepage resolves to the administrator landing instead of another authentication page');
    foreach (['/', '/login', '/register', '/admin', 'https://example.test/'] as $landing) {
        [$status, , , $headers] = $request('/login?next=%2F', actor: 'member', extraHeaders: ['X-Test-Landing: ' . $landing]);
        $assert($status === 302 && $headers['location'] === '/', 'Login normalizes root, authentication and external landing targets: ' . $landing);
        [$status, , $body, $headers] = $request('/', actor: 'member', extraHeaders: ['X-Test-Landing: ' . $landing]);
        $assert($status === 200 && !isset($headers['location']) && str_contains($body, 'My account'), 'Redirect chain terminates at the authenticated homepage: ' . $landing);
    }
    [$status, , , $headers] = $request('/', actor: 'member', extraHeaders: ['X-Test-Landing: /crm']);
    $assert($status === 302 && $headers['location'] === '/crm', 'A module-provided CRM landing remains supported');
    [$status, , , $headers] = $request('/?next=%2Frecovery', actor: 'member');
    $assert($status === 302 && $headers['location'] === '/recovery', 'Homepage login preserves a valid explicit next destination');
    foreach (['/login', '/register', '/admin'] as $next) {
        [$status, , $body, $headers] = $request('/?next=' . rawurlencode($next), actor: 'member');
        $assert($status === 200 && !isset($headers['location']) && str_contains($body, 'My account'), 'Authentication and unauthorized next destinations cannot create a homepage loop: ' . $next);
    }
    [$status] = $request('/api/settings', 'PATCH', ['settings' => ['site.public_enabled' => false]], 'admin');
    $assert($status === 200, 'Core-only settings can close the public surface');
    foreach (['' => '/login', 'member' => '/account', 'admin' => '/admin'] as $actor => $target) {
        [$status, , , $headers] = $request('/', actor: $actor);
        $assert($status === 302 && $headers['location'] === $target, 'Closed core-only root resolves access without module dependencies: ' . $actor);
    }
    [$status, , $body] = $request('/account', actor: 'member');
    $assert($status === 200 && str_contains($body, 'My account') && !str_contains($body, 'class="navbar"'), 'Closed core-only member account renders the access shell');
    [$status] = $request('/api/settings', 'PATCH', ['settings' => ['site.public_enabled' => true]], 'admin');
    $assert($status === 200, 'Core-only settings can reopen the public surface without a build');
    // Use the same physical copy (without Social) to exercise shared inbox/health services.
    \TinyCat\Core\Notifications\Schema::install($pdo);
    $foreignTables = array_column($pdo->query("SELECT REFERENCED_TABLE_NAME AS `table` FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC), 'table');
    $assert(array_diff($foreignTables, ['users']) === [], 'Fresh inbox has no foreign keys to publication or social tables');
    $savedRegistries = \TinyCat\Core\Modules\Registrations::current();
    $orderType = new \TinyCat\Core\Modules\NotificationType('fixture.order', static fn (array $rows): array => $rows, static fn (array $row): string => '/orders/' . (int) json_decode((string) $row['payload_json'], true)['order_id']);
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries(['fixture.platform' => new \TinyCat\Core\Modules\Contributions(notificationTypes: [$orderType, ...\TinyCat\Core\Tasks\Health\Alerts::types()], tasks: [\TinyCat\Core\Tasks\Health\Inspection::scheduledTask()])]));
    $notice = \TinyCat\Core\Notifications\Delivery::record(1, 'fixture.order', 'order:42', payload: ['order_id' => 42]);
    $assert($notice['created'] && \TinyCat\Core\Notifications\Inbox::open($notice['id'], 1) === '/orders/42', 'Opaque non-publication subjects work without Social');
    $duplicate = \TinyCat\Core\Notifications\Delivery::record(1, 'fixture.order', 'order:42', payload: ['order_id' => 42], refresh: false);
    $assert(!$duplicate['created'] && \TinyCat\Core\Notifications\Inbox::unreadCount(1) === 0, 'Idempotent system delivery preserves read state');
    $assert(\TinyCat\Core\Notifications\Inbox::open($notice['id'], 2) === '/notifications', 'Another account cannot open the notification');
    \TinyCat\Core\Settings\Store::reset();
    $inspection = \TinyCat\Core\Tasks\Execution::run('site-health', [], 'admin', 1)['tasks']['site-health'];
    $assert($inspection['ok'] && $inspection['changed'] === 1, 'Health delivers to the active administrator without Social or fake actors');
    $assert(\TinyCat\Core\Notifications\Inbox::items(1)[0]['actor_id'] === null, 'Inbox lists actorless system notices without social tables');
    \TinyCat\Core\Modules\Registrations::configure(new \TinyCat\Core\Modules\Registries());
    $assert(\TinyCat\Core\Notifications\Inbox::open($notice['id'], 1) === '/notifications', 'Inactive subject owner falls back safely');
    \TinyCat\Core\Modules\Registrations::configure($savedRegistries);

    $legacy = \tinycat_test_database();
    $legacy->exec('CREATE TABLE notifications (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, notification_key VARCHAR(190), read_at TEXT, created_at DATETIME, updated_at DATETIME)');
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
    [$status] = $request('/api/social/profiles/2/following', actor: 'member'); $assert($status === 404, 'Social endpoints are absent');
    [$status, , $svg] = $request('/avatar/initials/v3/ME.svg'); $assert($status === 200 && str_starts_with($svg, '<svg'), 'Avatar route is owned by Accounts');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    TinyCat\Database\Connection::set(\tinycat_test_database()); $pdo = null;
}
$assert(!is_file($workspace . '/product-loads.log'), 'All HTTP scenarios avoid loading product classes');
foreach (get_declared_classes() as $class) { $assert(!preg_match('/^TinyCat\\\\(?:Users|Status|Bots|Settings|Site|Moderation|Notifications)\\\\/', $class), 'Build avoids loading ' . $class); }
echo "PASS standalone Accounts module, schema, routes, HTML and build ({$checks} checks)\n";
