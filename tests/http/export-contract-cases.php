<?php
declare(strict_types=1);

$exportDb = new PDO('sqlite:' . $databasePath);
$exportDb->exec("INSERT INTO users (id, username, role, status) VALUES (900001, 'exportactive', 'bot', 'active'), (900002, 'exporthidden', 'bot', 'inactive')");
$insertExport = $exportDb->prepare('INSERT INTO content (id, author_id, body, published_at) VALUES (?, ?, ?, ?)');
$insertExport->execute([900001, 900001, "Export <script>bad</script> & ]]> \x01\n#ExportPublic #exportpublic https://example.test/page?a=1&b=2#fragment", '2020-01-01 00:00:00']);
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
foreach (['/robots.txt', '/llms.txt', '/llms-full.txt', '/sitemap.xml', '/sitemap-pages-1.xml', '/sitemap-tags-1.xml', '/sitemap-status-1.xml', '/author/900001/feed', '/tag/exportpublic/feed', '/search/feed?q=Export&media=text'] as $path) {
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
[$status, $rss] = $exportRequest('/search/feed?q=Export&media=images');
$assert($status === 200 && count(simplexml_load_string($rss)->channel->item) === 0, 'Search RSS applies the selected media filter.');
[$status] = $exportRequest('/search/feed?q=ab');
$assert($status === 404, 'Search RSS rejects queries below the post search minimum.');
[$status, $rss] = $exportRequest('/author/900001/feed?tracking=ignored');
$feed = simplexml_load_string($rss);
$feed->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
$selfLink = $feed->xpath('/rss/channel/atom:link/@href');
$assert((string) $selfLink[0] === 'http://localhost/author/900001/feed' && (string) $feed->channel->language === 'en', 'RSS has a canonical self URL and stable site language: ' . (string) $selfLink[0] . ' / ' . (string) $feed->channel->language);
$assert(count($feed->channel->item) === 1 && str_contains((string) $feed->channel->item->description, '&lt;script&gt;'), 'RSS descriptions preserve user text without injecting HTML.');
$assert(array_values(array_map('strval', iterator_to_array($feed->channel->item->category))) === ['exportpublic'], 'RSS categories are normalized and deduplicated hashtags, excluding URL fragments.');
$assert((string) $feed->channel->item->children('http://purl.org/dc/elements/1.1/')->creator === 'exportactive', 'RSS exports the public author name without inventing an email.');
$assert((string) $feed->xpath('/rss/channel/item/atom:author/atom:uri')[0] === 'http://localhost/author/900001', 'RSS includes the same canonical author URL as page metadata.');
$description = (string) $feed->channel->item->description;
$assert(str_contains($description, '<br>') && str_contains($description, '<a href="https://example.test/page?a=1&amp;b=2#fragment">') && !str_contains($description, '<script>'), 'RSS preserves line breaks and safe links while escaping user HTML.');
$schemaFromPage = static function (string $html): array {
    $document = new DOMDocument();
    libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors();
    return json_decode((new DOMXPath($document))->evaluate('string(//script[@type="application/ld+json"])'), true, 512, JSON_THROW_ON_ERROR);
};
$exportDb->exec("INSERT INTO content_comments (id, content_id, parent_id, user_id, body, created_at) VALUES
    (900001, 900001, NULL, 900001, 'Visible comment <b>literal</b>', '2020-01-02 00:00:00'),
    (900002, 900001, 900001, 900001, 'Visible reply', '2020-01-03 00:00:00'),
    (900003, 900001, NULL, 900002, 'HIDDEN-COMMENT-SECRET', '2020-01-04 00:00:00'),
    (900004, 900001, 900003, 900001, 'ORPHAN-REPLY-SECRET', '2020-01-05 00:00:00')");
[$status, $postHtml] = $exportRequest('/status/900001');
$postSchema = $schemaFromPage($postHtml);
$assert($status === 200 && str_contains($postSchema['text'], 'Export <script>') && !isset($postSchema['image']), 'Text-only post JSON-LD contains text and no invented image.');
$headDocument = new DOMDocument();
libxml_use_internal_errors(true); $headDocument->loadHTML($postHtml); libxml_clear_errors();
$headXPath = new DOMXPath($headDocument);
$assert($headXPath->evaluate('string(//meta[@property="article:author"]/@content)') === 'http://localhost/author/900001', 'Open Graph author is the canonical profile URL.');
$assert($headXPath->query('//meta[@property="article:tag"]')->length === 1 && $headXPath->evaluate('string(//meta[@property="article:tag"]/@content)') === 'exportpublic', 'Open Graph exports normalized article tags.');
$assert($headXPath->evaluate('string(//link[@type="application/rss+xml"]/@href)') === 'http://localhost/author/900001/feed', 'Post detail advertises its author RSS feed.');
[$status, $navigationBody] = $exportRequest('/api/site/pages?path=/status/900001');
$navigationDocument = json_decode($navigationBody, true, 512, JSON_THROW_ON_ERROR)['data']['document'];
$assert($status === 200 && $navigationDocument['tags'] === ['exportpublic'] && $navigationDocument['author'] === $postSchema['author']['url'] && $navigationDocument['rss'] === 'http://localhost/author/900001/feed', 'Client navigation carries the same article tags, author URL and RSS discovery as HTML.');
$commentSchema = $postSchema['comment'][0];
$assert(count($postSchema['comment']) === 1 && $commentSchema['text'] === 'Visible comment <b>literal</b>' && $commentSchema['comment'][0]['text'] === 'Visible reply', 'JSON-LD includes visible comments and keeps reply nesting.');
$assert($commentSchema['author']['url'] === $postSchema['author']['url'] && $commentSchema['datePublished'] === '2020-01-02T00:00:00+00:00' && $commentSchema['url'] === $postSchema['url'], 'Comment JSON-LD has canonical author and page URLs and its actual publication date.');
$assert(!str_contains(json_encode($postSchema), 'HIDDEN-COMMENT-SECRET') && !str_contains($postHtml, 'ORPHAN-REPLY-SECRET'), 'Structured data excludes hidden comments and replies missing their visible parent.');
$assert(!str_contains((string) $feed->channel->item->title, 'https://') && !str_contains((string) $feed->channel->item->title, '<script>'), 'RSS titles omit long URLs and HTML markup.');
$assert(str_starts_with((string) $feed->channel->image->url, 'http://localhost/') && (string) $feed->channel->image->link === 'http://localhost/author/900001', 'Author RSS includes an absolute avatar URL and matching channel link.');

[$status, $profileHtml] = $exportRequest('/author/900001');
$profileSchema = $schemaFromPage($profileHtml);
$assert($status === 200 && $profileSchema['hasPart'][0]['text'] === $postSchema['text'], 'Profile JSON-LD includes the same original post text as the detail.');
$assert($profileSchema['hasPart'][0]['author']['name'] === $profileSchema['mainEntity']['name'] && $profileSchema['hasPart'][0]['author']['@type'] === $profileSchema['mainEntity']['@type'], 'Profile post authors include the entity name and schema type directly.');

$assert($postSchema['author']['url'] === 'http://localhost/author/900001' && $profileSchema['mainEntity']['url'] === $postSchema['author']['url'] && $profileSchema['hasPart'][0]['author']['url'] === $postSchema['author']['url'], 'Post and profile JSON-LD expose the canonical author URL directly.');
$exportDb->exec("INSERT INTO content_images (content_id, path, width, height, bytes) VALUES (900001, '2026/11/0123456789abcdef0123456789abcdef.webp', 640, 480, 1234)");
[$status, $imageRss] = $exportRequest('/author/900001/feed');
$imageFeed = simplexml_load_string($imageRss);
$imageUrl = (string) $imageFeed->channel->item->enclosure['url'];
$assert($status === 200 && str_starts_with($imageUrl, 'http://localhost/') && (string) $imageFeed->channel->item->enclosure['length'] === '1234', 'RSS retains the absolute image enclosure and its real byte length.');
$assert((string) $imageFeed->channel->item->children('http://search.yahoo.com/mrss/')->content->attributes()['url'] === $imageUrl && str_contains((string) $imageFeed->channel->item->description, '<img src="' . $imageUrl . '" alt="'), 'RSS includes the same image in Media RSS and safe HTML with alternative text.');
[$status, $imageHtml] = $exportRequest('/status/900001');
$imageSchema = $schemaFromPage($imageHtml);
$assert($imageSchema['image']['contentUrl'] === $imageUrl && $imageSchema['image']['width'] === 640, 'JSON-LD and RSS agree on the attached image.');
// Compare direct HTML and client-navigation metadata while a real attachment exists.
foreach (['/status/900001', '/author/900001', '/tag/exportpublic', '/search?q=Export', '/pl/status/900001'] as $metadataPath) {
    [$status, $metadataHtml] = $exportRequest($metadataPath, 'GET', ['Accept-Language' => 'en']);
    $metadataDom = new DOMDocument();
    libxml_use_internal_errors(true); $metadataDom->loadHTML($metadataHtml); libxml_clear_errors();
    $metadataXPath = new DOMXPath($metadataDom);
    $navigationUrl = '/api/site/pages?path=' . rawurlencode((string) parse_url($metadataPath, PHP_URL_PATH));
    $metadataQuery = (string) parse_url($metadataPath, PHP_URL_QUERY);
    if ($metadataQuery !== '') { $navigationUrl .= '&' . $metadataQuery; }
    [$navigationStatus, $metadataJson] = $exportRequest($navigationUrl, 'GET', ['Accept-Language' => 'en']);
    $metadataDocument = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR)['data']['document'];
    $assert($status === 200 && $navigationStatus === 200 && $metadataXPath->evaluate('string(//title)') === $metadataDocument['title'], 'HTML and navigation share the document title: ' . $metadataPath);
    foreach (['og:title' => 'meta_title', 'og:url' => 'url', 'og:type' => 'type', 'og:image' => 'image', 'og:image:alt' => 'image_alt', 'og:locale' => 'locale', 'article:author' => 'author'] as $property => $field) {
        $assert($metadataXPath->evaluate('string(//meta[@property="' . $property . '"]/@content)') === $metadataDocument[$field], 'HTML and navigation share ' . $property . ': ' . $metadataPath);
    }
    $assert($metadataXPath->evaluate('string(//link[@type="application/rss+xml"]/@href)') === $metadataDocument['rss'], 'HTML and navigation advertise the same RSS URL: ' . $metadataPath);
    $assert($schemaFromPage($metadataHtml) === $metadataDocument['jsonld'], 'HTML and navigation expose identical JSON-LD: ' . $metadataPath);
    $schema = $metadataDocument['jsonld'];
    if (str_contains($metadataPath, '/author/')) { $listedPost = $schema['hasPart'][0]; }
    elseif (str_contains($metadataPath, '/tag/') || str_contains($metadataPath, '/search')) { $listedPost = $schema['mainEntity']['itemListElement'][0]['item']; }
    else { $listedPost = $schema; }
    foreach (['url', 'text', 'keywords', 'datePublished', 'author'] as $postField) {
        $assert($listedPost[$postField] === $imageSchema[$postField], 'All page contexts describe the same post ' . $postField . ': ' . $metadataPath);
    }
    $assert($listedPost['image']['contentUrl'] === $imageSchema['image']['contentUrl'] && $listedPost['image']['width'] === $imageSchema['image']['width'], 'All contexts use the same attached image URL and dimensions.');
}
foreach (['/author/900001', '/tag/exportpublic', '/search?q=Export'] as $filteredPage) {
    $separator = str_contains($filteredPage, '?') ? '&' : '?';
    [$status, $filteredHtml] = $exportRequest($filteredPage . $separator . 'media=images');
    $filteredDom = new DOMDocument();
    libxml_use_internal_errors(true); $filteredDom->loadHTML($filteredHtml); libxml_clear_errors();
    $filteredRssUrl = (new DOMXPath($filteredDom))->evaluate('string(//link[@type="application/rss+xml"]/@href)');
    $assert(str_contains($filteredRssUrl, 'media=images'), 'Filtered pages advertise a feed with the selected media filter.');
    $feedPath = (string) parse_url($filteredRssUrl, PHP_URL_PATH) . '?' . (string) parse_url($filteredRssUrl, PHP_URL_QUERY);
    [$status, $filteredRss] = $exportRequest($feedPath);
    $filteredFeed = simplexml_load_string($filteredRss);
    $assert($status === 200 && count($filteredFeed->channel->item) === 1, 'Filtered RSS includes the matching attached-image post.');
    $filteredFeed->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
    $assert((string) $filteredFeed->xpath('/rss/channel/atom:link/@href')[0] === $filteredRssUrl && str_contains((string) $filteredFeed->channel->link, 'media=images'), 'Filtered RSS self and page URLs retain the selected media filter.');
    [$status, $textOnlyRss] = $exportRequest(str_replace('media=images', 'media=text', $feedPath));
    $assert($status === 200 && count(simplexml_load_string($textOnlyRss)->channel->item) === 0, 'RSS text filtering excludes an attached-image post.');
}
$exportDb->exec('DELETE FROM content_images WHERE content_id = 900001');
$exportDb->exec("INSERT INTO links (id, normalized_url, url_hash, link_type, image_url) VALUES (900001, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'export-link-image', 'video', '')");
$exportDb->exec("INSERT INTO content_links (content_id, link_id) VALUES (900001, 900001)");
[$status, $previewHtml] = $exportRequest('/status/900001');
$assert($schemaFromPage($previewHtml)['image'] === 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', 'Post JSON-LD retains the link preview image when no attachment exists.');
[$status, $previewProfile] = $exportRequest('/author/900001');
$assert($schemaFromPage($previewProfile)['hasPart'][0]['image'] === 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', 'Profile posts retain the same link preview fallback.');
foreach (['/author/900001/feed', '/tag/exportpublic/feed', '/search/feed?q=Export'] as $previewFeedUrl) {
    [$status, $previewRss] = $exportRequest($previewFeedUrl);
    $previewFeed = simplexml_load_string($previewRss);
    $previewMedia = $previewFeed->channel->item->children('http://search.yahoo.com/mrss/')->content->attributes();
    $assert($status === 200 && (string) $previewMedia['url'] === 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg' && str_contains((string) $previewFeed->channel->item->description, '<img src="https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg"'), 'RSS uses the same link preview fallback as JSON-LD: ' . $previewFeedUrl);
    $assert(!isset($previewMedia['type']) && count($previewFeed->channel->item->enclosure) === 0, 'Preview RSS does not invent an attachment byte length or declare JPEG thumbnails as WebP.');
}
$exportDb->exec("INSERT INTO content_images (content_id, path, width, height, bytes) VALUES (900001, '2026/11/0123456789abcdef0123456789abcdef.webp', 640, 480, 1234)");
[$status, $priorityRss] = $exportRequest('/author/900001/feed');
$priorityFeed = simplexml_load_string($priorityRss);
$assert((string) $priorityFeed->channel->item->enclosure['url'] === $imageUrl && !str_contains((string) $priorityFeed->channel->item->description, 'i.ytimg.com'), 'An attached image takes priority over a link preview in RSS.');
$exportDb->exec('DELETE FROM content_images WHERE content_id = 900001');
$exportDb->exec('DELETE FROM content_links WHERE content_id = 900001');
$exportDb->exec('DELETE FROM links WHERE id = 900001');

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
$exportDb->exec('DELETE FROM content_comments WHERE content_id >= 900001');
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
