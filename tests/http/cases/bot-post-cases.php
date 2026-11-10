<?php
declare(strict_types=1);

$botPostsDb = new PDO('sqlite:' . $databasePath);
foreach (['/admin/bots/accounts' => 'bot-accounts-filter-modal', '/admin/bots/list' => 'bots-filter-modal'] as $pagePath => $filterId) {
    $filterPage = file_get_contents('http://' . $address . $pagePath, false, stream_context_create(['http' => ['header' => "X-Test-Role: admin\r\nAccept: text/html", 'ignore_errors' => true]]));
    $pageHeaders = http_get_last_response_headers() ?? [];
    $assert(preg_match('/\s200\s/', (string) ($pageHeaders[0] ?? '')) === 1 && is_string($filterPage) && str_contains($filterPage, 'id="' . $filterId . '"') && str_contains($filterPage, 'data-filter-content'), 'Bot administration pages render their dropdown filter form successfully: ' . $pagePath);
}
$seedBotPost = $botPostsDb->prepare('INSERT INTO content (id, author_id, body, published_at) VALUES (?, 3, ?, ?)');
foreach (range(1, 31) as $number) {
    $seedBotPost->execute([94000 + $number, 'Managed bot post ' . $number, '2999-01-' . sprintf('%02d', $number) . ' 12:00:00']);
}
$botPage = file_get_contents('http://' . $address . '/admin/bots/3', false, stream_context_create(['http' => ['header' => "X-Test-Role: admin\r\nAccept: text/html", 'ignore_errors' => true]]));
$assert(is_string($botPage) && str_contains($botPage, 'data-bot-detail-open="3"') && str_contains($botPage, 'id="bot-accounts-list"'), 'Direct bot links host the tabbed detail modal over the accounts list.');
$retiredBotPage = file_get_contents('http://' . $address . '/admin/bots/3?view=page', false, stream_context_create(['http' => ['header' => "X-Test-Role: admin\r\nAccept: text/html", 'ignore_errors' => true]]));
$assert(is_string($retiredBotPage) && str_contains($retiredBotPage, 'data-bot-detail-open="3"') && !str_contains($retiredBotPage, 'id="bot-posts-list"'), 'The retired page flag cannot restore the classic bot detail.');
[$status] = $request('GET', '/api/bots/accounts/3/posts');
$assert($status === 401, 'Bot post administration requires authentication.');
[$status] = $request('GET', '/api/bots/accounts/3/posts', null, 'user');
$assert($status === 403, 'Members cannot enumerate scheduled bot posts.');
[$status, $modalPosts] = $request('GET', '/api/bots/accounts/3/posts?scope=modal&per_page=10', null, 'admin');
$assert($status === 200 && $modalPosts['data']['history_url'] === false && $modalPosts['data']['list_target'] === '#bot-detail-posts-list', 'Modal post lists have their own target and do not change the parent page URL.');
$assert($modalPosts['data']['sorting']['body']['history'] === false && str_contains($modalPosts['data']['sorting']['body']['url'], 'scope=modal'), 'Modal post sorting preserves its scope and isolates browser history.');
[$status, $managedPosts] = $request('GET', '/api/bots/accounts/3/posts?per_page=10&sort=published_at&direction=asc', null, 'admin');
$assert($status === 200 && count($managedPosts['data']['items']) === 10 && $managedPosts['meta']['pagination']['total'] === 31, 'The standard bot post list is paginated rather than limited to eight recent posts.');
$assert($managedPosts['data']['items'][0]['id'] === 94001 && !$managedPosts['data']['items'][0]['published'] && $managedPosts['data']['items'][0]['url'] === '', 'Scheduled posts are marked and do not link to an unavailable public page.');
[$status, $nextPosts] = $request('GET', '/api/bots/accounts/3/posts?per_page=10&page=2&sort=published_at&direction=asc', null, 'admin');
$assert($status === 200 && $nextPosts['data']['items'][0]['id'] === 94011, 'Bot list pagination and sorting have a stable second page.');
[$status, $filteredPosts] = $request('GET', '/api/bots/accounts/3/posts?q=Managed%20bot%20post%2031', null, 'admin');
$assert($status === 200 && count($filteredPosts['data']['items']) === 1 && $filteredPosts['data']['items'][0]['id'] === 94031, 'The bot post list supports the shared search control.');
$assert($filteredPosts['data']['total_count'] === 31, 'Filtering the list does not change the account post statistic.');
[$status] = $request('GET', '/api/bots/accounts/3/posts/94001/management');
$assert($status === 401, 'The edit modal endpoint is not public.');
[$status] = $request('GET', '/api/bots/accounts/3/posts/94001/management', null, 'user');
$assert($status === 403, 'Members cannot load a scheduled bot post editor.');
[$status, $editBotPost] = $request('GET', '/api/bots/accounts/3/posts/94001/management?panel=edit', null, 'admin');
$assert($status === 200 && $editBotPost['data']['item']['editable_body'] === 'Managed bot post 1' && $editBotPost['data']['item']['capabilities']['edit'] && isset($editBotPost['data']['options']['images_enabled']), 'The admin endpoint supplies the existing post edit modal payload.');
[$status] = $request('GET', '/api/bots/accounts/3/posts/11/management', null, 'admin');
$assert($status === 404, 'A bot management URL cannot fetch another author’s post.');
[$status] = $request('GET', '/api/statuses/94001', null, 'admin');
$assert($status === 404, 'Public API visibility stays unchanged for administrators.');
[$status] = $request('PATCH', '/api/statuses/94001', ['body' => 'Updated scheduled bot post'], 'admin');
$assert($status === 403, 'The shared edit API still enforces CSRF.');
[$status, $updatedBotPost] = $request('PATCH', '/api/statuses/94001', ['body' => 'Updated scheduled bot post'], 'admin', $csrf);
$assert($status === 200 && $updatedBotPost['code'] === 'status_saved', 'Scheduled bot posts save through the normal post editor API.');
$storedBotPost = $botPostsDb->query('SELECT body, published_at FROM content WHERE id = 94001')->fetch(PDO::FETCH_ASSOC);
$assert($storedBotPost['body'] === 'Updated scheduled bot post' && $storedBotPost['published_at'] === '2999-01-01 12:00:00', 'Editing preserves the queued publication time.');
[$status] = $request('GET', '/api/statuses/94001');
$assert($status === 404, 'Saving a scheduled post does not publish it early.');
[$status] = $request('DELETE', '/api/statuses/94001', [], 'admin', $csrf);
$assert($status === 200 && (int) $botPostsDb->query('SELECT COUNT(*) FROM content WHERE id = 94001')->fetchColumn() === 0, 'Scheduled posts can be deleted through the standard confirmed-delete API.');
$botPostsDb->exec('DELETE FROM content WHERE id BETWEEN 94001 AND 94031');
$seedBotPost = null;
$botPostsDb = null;
