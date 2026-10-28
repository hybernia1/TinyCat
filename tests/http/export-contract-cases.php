<?php
declare(strict_types=1);

$exportDb = new PDO('sqlite:' . $databasePath);
$exportDb->exec("INSERT INTO users (id, username, role, status) VALUES (900001, 'exportactive', 'bot', 'active'), (900002, 'exporthidden', 'bot', 'inactive')");
$insertExport = $exportDb->prepare('INSERT INTO content (id, author_id, body, published_at) VALUES (?, ?, ?, ?)');
$insertExport->execute([900001, 900001, "Export <script>bad</script> & ]]> \x01", '2020-01-01 00:00:00']);
$insertExport->execute([900002, 900001, 'FUTURE-EXPORT-SECRET', '2999-01-01 00:00:00']);
$insertExport->execute([900003, 900002, 'HIDDEN-EXPORT-SECRET', '2020-01-01 00:00:00']);
$exportDb->exec("INSERT INTO terms (id, name) VALUES (900001, 'exportpublic'), (900002, 'exportorphan'), (900003, 'exportfuture')");
$exportDb->exec('INSERT INTO content_tags VALUES (900001, 900001), (900002, 900003)');
$exportRequest = static function (string $path, string $method = 'GET', array $extra = []) use ($address, $assert): array {
    $headers = ['Accept-Language' => 'cs', ...$extra];
    $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", array_map(static fn (string $key, string $value): string => $key . ': ' . $value, array_keys($headers), array_values($headers)))]]);
    $body = file_get_contents('http://' . $address . $path, false, $context);
    $lines = http_get_last_response_headers() ?? [];
    preg_match('/\s(\d{3})\s/', $lines[0] ?? '', $match);
    $responseHeaders = [];
    foreach ($lines as $line) { if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $responseHeaders[strtolower($key)] = trim($value); } }
    $assert(is_string($body), 'Machine-readable endpoints complete HTTP requests.');
    return [(int) ($match[1] ?? 0), (string) $body, $responseHeaders];
};
foreach (['/robots.txt', '/llms.txt', '/llms-full.txt', '/sitemap.xml', '/sitemap-pages-1.xml', '/sitemap-tags-1.xml', '/sitemap-status-1.xml', '/author/900001/feed', '/tag/exportpublic/feed'] as $path) {
    [$status, $body, $headers] = $exportRequest($path);
    $assert($status === 200 && isset($headers['etag']) && str_contains($headers['cache-control'], 'public'), $path . ' exposes shared validators and public cache headers.');
    $assert(!isset($headers['set-cookie']) && !isset($headers['pragma']), $path . ' does not create a visitor session.');
    $assert(!str_contains($body, 'FUTURE-EXPORT-SECRET') && !str_contains($body, 'HIDDEN-EXPORT-SECRET'), 'Documents exclude hidden and future content even for administrators.');
    [$headStatus, $headBody, $headHeaders] = $exportRequest($path, 'HEAD');
    $assert($headStatus === 200 && $headBody === '' && $headHeaders['etag'] === $headers['etag'], 'HEAD shares GET metadata without a response body.');
    [$unchanged, $empty, $cachedHeaders] = $exportRequest($path, 'GET', ['If-None-Match' => '"unrelated", W/' . $headers['etag']]);
    $assert($unchanged === 304 && $empty === '' && isset($cachedHeaders['cache-control']), 'Conditional GET accepts weak validators in a list and returns an empty 304.');
    [$otherStatus, $otherBody] = $exportRequest($path, 'GET', ['Accept-Language' => 'en', 'X-Test-Role' => 'admin']);
    $assert($otherStatus === 200 && $otherBody === $body, 'Export representations are independent of visitor identity and language.');
    if (str_contains($headers['content-type'], 'xml')) {
        $assert(simplexml_load_string($body) !== false, $path . ' returns well-formed XML.');
    }
}
[$status, $rss] = $exportRequest('/author/900001/feed?tracking=ignored');
$feed = simplexml_load_string($rss);
$feed->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
$selfLink = $feed->xpath('/rss/channel/atom:link/@href');
$assert((string) $selfLink[0] === 'http://localhost/author/900001/feed' && (string) $feed->channel->language === 'en', 'RSS has a canonical self URL and stable site language: ' . (string) $selfLink[0] . ' / ' . (string) $feed->channel->language);
$assert(count($feed->channel->item) === 1 && str_contains((string) $feed->channel->item->description, '&lt;script&gt;'), 'RSS descriptions preserve user text without injecting HTML.');
[$status, $old, $oldHeaders] = $exportRequest('/author/900001/feed');
$exportDb->exec("UPDATE content SET body = 'Updated export' WHERE id = 900001");
[$status, $changed, $changedHeaders] = $exportRequest('/author/900001/feed', 'GET', ['If-None-Match' => $oldHeaders['etag']]);
$assert($status === 200 && $oldHeaders['etag'] !== $changedHeaders['etag'], 'Changed content invalidates the RSS representation immediately.');
foreach (['/sitemap-unknown-1.xml', '/sitemap-status-999999999999999999999.xml', '/author/900002/feed', '/tag/missingexport/feed'] as $path) {
    [$status, $body, $headers] = $exportRequest($path);
    $assert($status === 404 && $headers['cache-control'] === 'no-store', 'Missing documents and overflowing sitemap pages return non-cacheable 404 responses.');
}
$exportDb->exec('DELETE FROM content_tags WHERE term_id >= 900001');
$exportDb->exec('DELETE FROM terms WHERE id >= 900001');
$exportDb->exec('DELETE FROM content WHERE id >= 900001');
$exportDb->exec('DELETE FROM users WHERE id >= 900001');
$insertExport = null;
$exportDb = null;

foreach (['/site.webmanifest'] as $path) {
    [$status, $body, $headers] = $exportRequest($path);
    $manifest = json_decode($body, true);
    $assert($status === 200 && is_array($manifest) && $manifest['lang'] === 'en', 'Manifest uses the configured site language and JSON media type.');
    [$status, $empty, $headHeaders] = $exportRequest($path, 'HEAD');
    $assert($status === 200 && $empty === '' && $headHeaders['etag'] === $headers['etag'], 'Manifest HEAD shares GET validators.');
    [$status, $empty, $cachedHeaders] = $exportRequest($path, 'GET', ['If-None-Match' => 'W/' . $headers['etag']]);
    $assert($status === 304 && $empty === '' && isset($cachedHeaders['cache-control']), 'Manifest uses shared conditional response handling.');
}
foreach (['/uploads/missing.webp', '/assets/missing.js', '/missing-page', '/avatar/missingexport'] as $path) {
    [$status, $body, $headers] = $exportRequest($path);
    $assert($status === 404 && str_contains($headers['cache-control'], 'no-store'), 'Missing resources retain 404 and cannot inherit long asset cache lifetimes.');
    if (str_ends_with($path, '.webp') || str_starts_with($path, '/avatar/')) {
        $assert(str_contains($headers['content-type'], 'image/svg+xml') && simplexml_load_string($body) !== false && !str_contains($body, '<html'), 'Image failures return a fixed, valid SVG instead of HTML.');
    } elseif (str_ends_with($path, '.js')) {
        $assert(str_contains($headers['content-type'], 'text/plain') && !str_contains($body, '<html'), 'Script failures never return an HTML error document.');
    } else { $assert(str_contains($body, '404') && str_contains($headers['content-type'], 'text/html'), 'Page failures render the regular error layout.'); }
    [$status, $empty] = $exportRequest($path, 'HEAD');
    $assert($status === 404 && $empty === '', 'Missing-resource HEAD retains status and has no body.');
}
[$status, $body, $headers] = $exportRequest('/privacy', 'DELETE');
$assert($status === 405 && str_contains($body, '405') && !preg_match('/aria-hidden="true">404/', $body) && isset($headers['allow']), 'Method errors render their real status and preserve the Allow header.');
[$status, $body, $headers] = $exportRequest('/fixture-failure');
$assert($status === 500 && str_contains($body, '500') && !str_contains($body, 'PRIVATE_') && !str_contains($body, 'Stack trace') && str_contains($headers['cache-control'], 'no-store'), 'Broken-database failures discard partial output and return a safe standalone 500 page.');
[$status, $body, $headers] = $exportRequest('/fixture-maintenance', 'GET', ['Accept' => 'application/json']);
$maintenance = json_decode($body, true);
$assert($status === 503 && $maintenance['code'] === 'maintenance' && $maintenance['status'] === 503 && !$maintenance['ok'] && $headers['retry-after'] === '300', 'Maintenance follows the shared API envelope and retry semantics.');
[$status, $body] = $exportRequest('/assets/fallback/image.svg');
$assert($status === 200 && simplexml_load_string($body) !== false, 'The static image placeholder is independently available with status 200.');

[$status, $body] = $exportRequest('/admin', 'POST', ['X-Test-Role' => 'admin']);
$assert($status === 405 && str_contains($body, '405') && str_contains($body, '<html'), 'Retired dashboard writes return a shared HTML 405 page.');
[$status, $body] = $exportRequest('/admin', 'POST', ['X-Test-Role' => 'admin'] + $csrf);
$assert($status === 405 && str_contains($body, '405'), 'A valid CSRF token cannot revive legacy dashboard writes.');

[$status, $body, $headers] = $exportRequest('/privacy');
$policyDocument = new DOMDocument();
libxml_use_internal_errors(true); $policyDocument->loadHTML($body); libxml_clear_errors();
$policyXPath = new DOMXPath($policyDocument);
$policyCanonical = $policyXPath->evaluate('string(//head/link[@rel="canonical"]/@href)');
$policySchema = json_decode($policyXPath->evaluate('string(//script[@type="application/ld+json"])'), true);
$assert($status === 200 && $policyCanonical === 'http://localhost/privacy' && !str_contains(strtolower($headers['x-robots-tag'] ?? ''), 'noindex'), 'Privacy policy is public and uses its stable canonical URL.');
$assert($policyXPath->evaluate('string(//head/link[@rel="privacy-policy"]/@href)') === $policyCanonical && $policyXPath->query('//footer//a[@rel="privacy-policy"]')->length === 1, 'Head metadata and the shared visible footer identify the same policy document.');
$assert($policySchema['@type'] === 'WebPage' && $policySchema['url'] === $policyCanonical && $policySchema['inLanguage'] === $policyXPath->evaluate('string(/html/@lang)'), 'Policy structured data describes the rendered page with matching URL and language.');
$assert($policyXPath->evaluate('string(//meta[@property="og:type"]/@content)') === 'website' && !isset($policySchema['dateModified']), 'Policy metadata does not present it as an article or invent an update date.');
