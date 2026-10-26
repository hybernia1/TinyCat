<?php
declare(strict_types=1);

foreach (['/' => 'home', '/tag/test' => 'tag', '/author/2' => 'author', '/status/10' => 'status'] as $path => $kind) {
    [$status, $page, $headers] = $request('GET', '/api/site/pages?path=' . rawurlencode($path));
    $assert($status === 200 && $page['data']['kind'] === $kind && $page['data']['viewer'] === null, 'Public navigation returns the same anonymous page model for each supported route.');
    $assert(!isset($page['data']['context']) && !isset($page['data']['csrf']) && str_contains(implode(' ', $headers), 'no-store'), 'Public page resources exclude internal preparations and cannot be shared across visitors.');
    [$documentStatus, $html] = $exportRequest($path, 'GET', ['Accept-Language' => 'en']);
    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors(); $xpath = new DOMXPath($dom);
    $assert($documentStatus === 200 && $xpath->query('//*[@data-public-page="' . $kind . '"]')->length === 1, 'Direct document requests render the shared page component without JavaScript.');
    $assert($xpath->evaluate('string(//head/link[@rel="canonical"]/@href)') === $page['data']['document']['url'] && $xpath->evaluate('string(//head/title)') === $page['data']['document']['title'], 'Direct documents and navigation resources retain identical canonical URLs and titles.');
}
[$status, $following] = $request('GET', '/api/site/pages?path=/&feed=following');
$assert($status === 200 && $following['data']['login_required'] && $following['data']['items'] === [] && $following['data']['url'] === '/?feed=following', 'Anonymous following navigation preserves its URL and login restriction.');
[$status, $own] = $request('GET', '/api/site/pages?path=/author/2', null, 'user');
$assert($status === 200 && $own['data']['composer'] && $own['data']['can_edit'] && $own['data']['viewer']['id'] === 2 && !isset($own['data']['viewer']['email']), 'Own-profile navigation exposes permitted controls without leaking private account fields.');
[$documentStatus, $ownHtml] = $exportRequest('/author/2', 'GET', ['X-Test-Role' => 'user']);
$dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($ownHtml); libxml_clear_errors(); $xpath = new DOMXPath($dom);
$assert($documentStatus === 200 && $xpath->query('//form[@data-status-form and @action="/api/statuses"]//input[@name="_csrf"]')->length === 1, 'Direct own-profile documents retain the standard composer and CSRF protection.');
$assert($xpath->query('//*[@data-modal-open and contains(@class, "profile-edit-toggle")]')->length === 1, 'Shared profile composition retains the standard profile editor hook.');
[$status, $other] = $request('GET', '/api/site/pages?path=/author/1', null, 'user');
$assert($status === 200 && !$other['data']['composer'] && !$other['data']['can_edit'] && $other['data']['can_follow'], 'Other profiles retain their original edit and posting permissions.');
[$status, $compact] = $request('GET', '/api/site/pages?path=/status/10&compact=1');
$assert($status === 200 && !$compact['data']['sidebar'] && $compact['data']['url'] === '/status/10?compact=1', 'Compact post navigation retains its URL without a sidebar.');
foreach (['/admin/users', '/status/99999999', '/author/99999999', 'https://example.com/'] as $path) {
    [$status] = $request('GET', '/api/site/pages?path=' . rawurlencode($path));
    $assert($status === 404, 'Page navigation cannot dispatch private, missing or external routes.');
}
[$status] = $request('GET', '/api/site/pages?path[]=invalid');
$assert($status === 422, 'Malformed page paths use the standard validation envelope.');
