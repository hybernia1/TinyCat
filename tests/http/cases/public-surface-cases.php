<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

// Change the real runtime setting in the existing disposable HTTP fixture.
[$status] = $request('PATCH', '/api/settings', ['settings' => ['site.public_enabled' => false]], 'admin', $csrf);
$assert($status === 200, 'Administrators can disable the public website through the existing settings API.');
foreach (['', 'user', 'admin'] as $role) {
    foreach (['/api/site/pages?path=/', '/api/site/sidebar', '/api/search?q=Export', '/api/search/query?q=Export', '/api/statuses', '/api/statuses/10', '/api/profiles/2'] as $path) {
        [$status, $payload] = $request('GET', $path, null, $role);
        $assert($status === 404 && $payload['code'] === 'not_found', 'Closed public JSON resources use the shared 404 for every role: ' . $path);
    }
}
foreach (['/social', '/en/social', '/status/10', '/cs/status/10', '/author/2', '/tag/test', '/search/all?q=Export', '/author/2/feed', '/tag/test/feed', '/search/feed?q=Export', '/status/10/embed', '/status/10/embed-code', '/oembed?url=http://localhost/status/10', '/sitemap.xml', '/sitemap-pages-1.xml', '/llms.txt', '/llms-full.txt'] as $path) {
    [$status, $body] = $exportRequest($path, 'GET', ['X-Test-Role' => 'admin']);
    $assert($status === 404 && !str_contains($body, 'data-public-page='), 'Closed public documents and transports do not render content: ' . $path);
}
[$status, $body] = $exportRequest('/robots.txt');
$assert($status === 200 && str_contains($body, 'Disallow: /') && !str_contains($body, 'Sitemap:'), 'Closed-site robots do not advertise public discovery.');
foreach (['/login', '/recovery', '/privacy', '/en/privacy'] as $path) {
    [$status, $body] = $exportRequest($path);
    $assert($status === 200 && !str_contains($body, 'class="navbar"') && !str_contains($body, 'data-global-search'), 'Essential anonymous pages retain the minimal access shell: ' . $path);
}
foreach (['' => '/login', 'user' => '/account', 'admin' => '/admin'] as $role => $destination) {
    [$status, , $headers] = $exportRequest('/', 'GET', $role !== '' ? ['X-Test-Role' => $role] : []);
    $assert($status === 302 && ($headers['location'] ?? '') === $destination, 'Closed-site root resolves the appropriate access destination: ' . $role);
}
[$status, $account] = $exportRequest('/account', 'GET', ['X-Test-Role' => 'user']);
$assert($status === 200 && str_contains($account, 'users/2/management') && !str_contains($account, 'data-public-page='), 'Members retain an account page while Social remains installed and the website is disabled.');
[$status, $payload] = $request('GET', '/api/users/authentication');
$assert($status === 200, 'Anonymously accessible authentication resources are independent of public website availability.');
[$status, $payload] = $request('POST', '/api/users/sessions', ['username' => 'member', 'password' => 'New-password-45', 'next' => '/en/author/2'], '', $csrf);
$assert($status === 200 && $payload['data']['redirect'] === '/account', 'Login rejects a closed localized destination and resolves the member landing without a redirect loop.');
[$status] = $request('GET', '/api/users', null, 'admin');
$assert($status === 200, 'Disabling the website preserves authorized administration.');
[$status] = $request('GET', '/api/notifications', null, 'user');
$assert($status === 200, 'Disabling the website preserves the authenticated core inbox API.');
$closedDb = \tinycat_test_database($databasePath);
$closedDb->exec("INSERT INTO social_posts (id, author_id, body, published_at) VALUES (980001, 3, 'Closed website draft', '2999-01-01 12:00:00')");
foreach (['' => 401, 'user' => 403] as $role => $expected) {
    [$status] = $request('PATCH', '/api/bots/accounts/3/posts/980001', ['body' => 'Forbidden'], $role, $csrf);
    $assert($status === $expected, 'Internal bot mutations retain authorization with the website disabled.');
}
[$status] = $request('PATCH', '/api/bots/accounts/3/posts/980001', ['body' => 'Forbidden'], 'admin');
$assert($status === 403, 'Internal bot mutations require CSRF.');
[$status] = $request('DELETE', '/api/bots/accounts/1/posts/980001', [], 'admin', $csrf);
$assert($status === 404, 'Internal bot mutations reject another account owner.');
[$status, $editor] = $request('GET', '/api/bots/accounts/3/posts/980001/management', null, 'admin');
$assert($status === 200 && $editor['data']['update_url'] === '/api/bots/accounts/3/posts/980001' && isset($editor['data']['constraints']), 'Internal editing provides its own canonical write endpoint and constraints.');
[$status] = $request('GET', '/api/bots/accounts/3/posts/980001/suggestions?q=mem&type=user', null, 'admin');
$assert($status === 200, 'Internal editor suggestions remain available without the public website.');
[$status] = $request('PATCH', '/api/bots/accounts/3/posts/980001', ['body' => 'Updated closed website draft'], 'admin', $csrf);
$assert($status === 200 && $closedDb->query('SELECT body FROM social_posts WHERE id = 980001')->fetchColumn() === 'Updated closed website draft', 'Administration updates bot posts independently of the public API.');
[$status] = $request('DELETE', '/api/bots/accounts/3/posts/980001', [], 'admin', $csrf);
$assert($status === 200 && (int) $closedDb->query('SELECT COUNT(*) FROM social_posts WHERE id = 980001')->fetchColumn() === 0, 'Administration deletes bot posts with the website disabled.');
$closedDb = null;
foreach (['admin' => true, '' => false] as $role => $adminShell) {
    [$status, $body] = $exportRequest('/admin/fixture-error/404', 'GET', $role !== '' ? ['X-Test-Role' => $role] : []);
    $assert($status === 404 && str_contains($body, 'data-admin-shell') === $adminShell && !str_contains($body, 'class="navbar"'), 'Admin errors select an authorized shell independently of public availability.');
}
[$status] = $request('PATCH', '/api/settings', ['settings' => ['site.public_enabled' => true]], 'admin', $csrf);
$assert($status === 200, 'The public website can be enabled again without installation or build.');
[$status, $payload] = $request('GET', '/api/site/pages?path=/author/2');
$assert($status === 200 && $payload['data']['kind'] === 'author', 'Re-enabling restores owner page resources in the same running fixture.');
