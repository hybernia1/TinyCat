<?php
declare(strict_types=1);

$embedDb = new PDO('sqlite:' . $databasePath);
$embedDb->exec("INSERT INTO users (id, username, role, status) VALUES (910001, 'embedauthor', 'user', 'active'), (910002, 'embedhidden', 'user', 'inactive')");
$insertEmbed = $embedDb->prepare('INSERT INTO content (id, author_id, body, published_at) VALUES (?, ?, ?, ?)');
$insertEmbed->execute([910001, 910001, "Embed <script>alert(1)</script> & #test https://example.com/path\nSecond line", '2020-01-01 12:00:00']);
$insertEmbed->execute([910002, 910001, 'FUTURE-EMBED-SECRET', '2999-01-01 12:00:00']);
$insertEmbed->execute([910003, 910002, 'HIDDEN-EMBED-SECRET', '2020-01-01 12:00:00']);
$embedUrl = '/oembed?url=' . rawurlencode('http://localhost/status/910001');
[$status, $body, $headers] = $exportRequest($embedUrl);
$embed = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
$assert($status === 200 && $embed['version'] === '1.0' && $embed['type'] === 'rich' && !isset($embed['data']), 'oEmbed uses the standard top-level representation without the application API envelope.');
$assert(str_starts_with($headers['content-type'], 'application/json') && !isset($headers['set-cookie']) && !isset($headers['pragma']), 'oEmbed is JSON and session-independent.');
$assert($embed['author_name'] === 'embedauthor' && $embed['author_url'] === 'http://localhost/author/910001' && $embed['width'] === 550 && $embed['height'] === 600, 'oEmbed describes the actual public post and provider dimensions.');
$dom = new DOMDocument(); @$dom->loadHTML($embed['html']); $xpath = new DOMXPath($dom);
$assert($xpath->query('//iframe')->length === 1 && $xpath->query('//script')->length === 0 && $xpath->evaluate('string(//iframe/@src)') === 'http://localhost/status/910001/embed?maxheight=600', 'oEmbed HTML is a standalone iframe with no required parent script.');
[$status, $body] = $exportRequest($embedUrl . '&maxwidth=320&maxheight=240');
$small = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
$assert($status === 200 && $small['width'] === 320 && $small['height'] === 240 && str_contains($small['html'], 'maxheight=240'), 'Consumer maximum dimensions constrain both metadata and the rendered frame.');
[$status, $xml] = $exportRequest($embedUrl . '&format=xml');
$xmlEmbed = simplexml_load_string($xml);
$assert($status === 200 && $xmlEmbed !== false && (string) $xmlEmbed->version === '1.0' && (string) $xmlEmbed->html === $embed['html'], 'XML oEmbed has equivalent fields and escaped HTML.');
[$status, $localized] = $exportRequest('/oembed?url=' . rawurlencode('http://localhost/cs/status/910001'));
$assert($status === 200 && json_decode($localized, true)['html'] === $embed['html'], 'Localized post URLs resolve to the same canonical embed.');
foreach (['', '&maxwidth=0', '&maxheight=-1', '&maxwidth[]=320', '&url[]=x'] as $query) {
    if ($query === '') { $query = '/oembed'; } else { $query = $embedUrl . $query; }
    [$status, $body, $badHeaders] = $exportRequest($query);
    $assert($status === 400 && str_contains($badHeaders['cache-control'], 'no-store'), 'Malformed oEmbed parameters are rejected without caching.');
}
[$status] = $exportRequest($embedUrl . '&format=yaml');
$assert($status === 501, 'Unsupported oEmbed formats use the standard 501 status.');
foreach (['https://external.example/status/910001', 'http://localhost.evil.example/status/910001', 'http://user@localhost/status/910001', 'file:///status/910001', 'http://localhost/author/910001', 'http://localhost:9999/status/910001', 'http://localhost/status/9999999999999999999999'] as $foreign) {
    [$status] = $exportRequest('/oembed?url=' . rawurlencode($foreign));
    $assert($status === 404, 'oEmbed accepts only this installation\'s public post URLs and never fetches arbitrary URLs.');
}
[$status, $html, $frameHeaders] = $exportRequest('/status/910001/embed');
$dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
$assert($status === 200 && !isset($frameHeaders['x-frame-options']) && str_contains($frameHeaders['content-security-policy'], 'frame-ancestors *'), 'Only the standalone embed permits cross-origin framing.');
$assert(!isset($frameHeaders['set-cookie']) && !isset($frameHeaders['pragma']) && str_contains($frameHeaders['cache-control'], 'must-revalidate'), 'Embed documents neither create sessions nor serve content from a long-lived public cache.');
$assert(str_contains($frameHeaders['x-robots-tag'], 'noindex') && $xpath->evaluate('string(//link[@rel="canonical"]/@href)') === 'http://localhost/status/910001', 'Embeds preserve the canonical original and are not independently indexed.');
$assert($xpath->query('//article[contains(@class,"embed-card")]')->length === 1 && $xpath->query('//form | //nav')->length === 0 && $xpath->query('//script[not(@src)]')->length === 0, 'The embed is a read-only card with no account shell or inline scripts.');
$assert(str_contains($xpath->evaluate('string(//*[@class="embed-body"])'), '<script>alert(1)</script>') && $xpath->query('//*[@class="embed-body"]//script')->length === 0 && $xpath->evaluate('string(//*[@class="embed-body"]//a[contains(@href,"/tag/")]/@href)') === 'http://localhost/tag/test', 'Embed text is escaped while mentions, tags and external links remain usable.');
$embedDb->exec("INSERT INTO content_images (content_id, path, width, height, bytes) VALUES (910001, '2026/10/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp', 640, 480, 1000)");
[$imageStatus, $imageHtml] = $exportRequest('/status/910001/embed');
$imageDom = new DOMDocument(); @$imageDom->loadHTML($imageHtml); $imageXPath = new DOMXPath($imageDom);
$assert($imageStatus === 200 && $imageXPath->query('//article//img[@width="640" and @height="480"]')->length === 1
    && $imageXPath->evaluate('string(//article//img[@width="640"]/@src)') === 'http://localhost/uploads/status-images/2026/10/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp', 'Attached images retain absolute media URLs and intrinsic dimensions.');
[$thumbnailStatus, $thumbnailBody] = $exportRequest($embedUrl);
$thumbnail = json_decode($thumbnailBody, true, flags: JSON_THROW_ON_ERROR);
$assert($thumbnailStatus === 200 && $thumbnail['thumbnail_width'] === 640 && $thumbnail['thumbnail_height'] === 480 && str_contains($thumbnail['thumbnail_url'], '/uploads/status-images/'), 'oEmbed exposes the attached image through standard thumbnail metadata.');
$embedDb->exec('DELETE FROM content_images WHERE content_id = 910001');
[$otherStatus, $otherHtml] = $exportRequest('/status/910001/embed', 'GET', ['Accept-Language' => 'cs', 'X-Test-Role' => 'admin']);
$assert($otherStatus === 200 && $otherHtml === $html, 'Embed output is independent of viewer identity and preferred language.');
[$errorStatus, $errorBody, $errorHeaders] = $exportRequest('/status/910001/embed', 'GET', ['X-Test-Embed-Failure' => '1']);
$assert($errorStatus === 500 && !isset($errorHeaders['set-cookie']) && !str_contains($errorBody, '<html') && !str_contains($errorBody, 'SELECT'), 'Embed failures stay session-free and do not fall back to the interactive application shell.');
[$status, $sharing] = $request('GET', '/api/statuses/910001/embed');
$assert($status === 200 && $sharing['data']['frame_url'] === 'http://localhost/status/910001/embed' && str_contains($sharing['data']['snippet'], '<iframe'), 'The standard modal receives its preview URL and copyable snippet through a public JSON resource.');
$assert(str_contains($html, '/avatar/generated/v1/910001.svg'), 'Authors without an upload use their generated avatar in embeds.');
$embedDb->exec("UPDATE users SET avatar_exists=1, updated_at='2020-01-01 12:00:00' WHERE id=910001");
[$status, $avatarHtml] = $exportRequest('/status/910001/embed');
$avatarDom = new DOMDocument(); @$avatarDom->loadHTML($avatarHtml); $avatarXPath = new DOMXPath($avatarDom);
$assert($status === 200 && str_starts_with($avatarXPath->evaluate('string(//img[@class="embed-avatar"]/@src)'), '/uploads/avatars/910001.webp?v='), 'The embed shows the author avatar with its normal versioned URL.');
$embedDb->exec('UPDATE users SET avatar_exists=0 WHERE id=910001');
$embedLinks = [];
$insertEmbedLink = $embedDb->prepare('INSERT INTO links (normalized_url, url_hash, provider, link_type, title, description, video_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([['https://www.youtube.com/watch?v=abcdefghijk', 'youtube', 'video', 'abcdefghijk'], ['https://soundcloud.com/forss/flickermood', 'soundcloud', 'audio', ''], ['https://open.spotify.com/track/0Lr4kGOYn9l83EjuK6cZFQ', 'spotify', 'audio', '']] as [$mediaUrl, $provider, $kind, $videoId]) {
    $insertEmbedLink->execute([$mediaUrl, hash('sha256', $mediaUrl), $provider, $kind, 'Media <script>unsafe</script>', 'Cached media summary', $videoId]);
    $linkId = (int) $embedDb->lastInsertId(); $embedLinks[] = $linkId;
    $embedDb->exec('INSERT INTO content_links (content_id, link_id) VALUES (910001, ' . $linkId . ')');
}
[$status, $mediaHtml, $mediaHeaders] = $exportRequest('/status/910001/embed');
$mediaDom = new DOMDocument(); @$mediaDom->loadHTML($mediaHtml); $mediaXPath = new DOMXPath($mediaDom);
$assert($status === 200 && $mediaXPath->query('//*[@data-embed-player]')->length === 3 && $mediaXPath->query('//iframe')->length === 0, 'Video, SoundCloud and Spotify have playable placeholders and do not load remote frames before interaction.');
$assert($mediaXPath->query('//*[@data-player-kind="audio"]')->length === 2 && str_contains($mediaHeaders['content-security-policy'], 'https://open.spotify.com') && str_contains($mediaHeaders['content-security-policy'], 'https://w.soundcloud.com'), 'Audio uses the central provider rules and explicit player origins.');
$assert($mediaXPath->query('//section[contains(@class,"embed-link")]//script')->length === 0 && str_contains($mediaHtml, '&lt;script&gt;unsafe&lt;/script&gt;'), 'Provider metadata remains escaped in rich media cards.');
[$status, $mediaMetadata] = $exportRequest($embedUrl);
$assert($status === 200 && str_starts_with(json_decode($mediaMetadata, true)['thumbnail_url'], 'https://i.ytimg.com/'), 'Video oEmbed responses include a standard thumbnail fallback.');
$embedDb->exec("UPDATE links SET normalized_url='https://evil.example/player', video_id='<script>' WHERE id=" . $embedLinks[0]);
[$status, $unsafeMedia] = $exportRequest('/status/910001/embed');
$unsafeDom = new DOMDocument(); @$unsafeDom->loadHTML($unsafeMedia); $unsafeXPath = new DOMXPath($unsafeDom);
$assert($status === 200 && $unsafeXPath->query('//*[@data-embed-player]')->length === 2 && !str_contains($unsafeMedia, 'data-player-url="https://evil.example'), 'Unapproved URLs and invalid provider identifiers cannot become player frames.');
$embedDb->exec('DELETE FROM content_links WHERE content_id=910001');
$embedDb->exec('DELETE FROM links WHERE id IN (' . implode(',', $embedLinks) . ')');
$insertEmbedLink = null;
[$status, $share, $shareHeaders] = $exportRequest('/status/910001/embed-code');
$dom = new DOMDocument(); @$dom->loadHTML($share); $xpath = new DOMXPath($dom);
$code = $xpath->evaluate('string(//textarea[@id="embed-code"])');
$assert($status === 200 && str_contains($code, '<iframe') && str_contains($code, '/assets/embed.js') && $shareHeaders['x-frame-options'] === 'SAMEORIGIN', 'The share page provides a selectable HTML snippet and retains normal frame protection.');
[$status, $detail, $detailHeaders] = $exportRequest('/status/910001');
$dom = new DOMDocument(); @$dom->loadHTML($detail); $xpath = new DOMXPath($dom);
$discovery = html_entity_decode($xpath->evaluate('string(//link[@type="application/json+oembed"]/@href)'));
$assert($status === 200 && $detailHeaders['x-frame-options'] === 'SAMEORIGIN' && str_contains($detailHeaders['content-security-policy'], "frame-ancestors 'self'"), 'Ordinary post pages retain frame protections.');
$assert(str_contains($discovery, '/oembed?url=') && $xpath->query('//link[@type="text/xml+oembed"]')->length === 1 && $xpath->query('//a[@href="/status/910001/embed-code" and @data-modal-open="status-embed-modal-910001" and @data-modal-url="/api/statuses/910001/embed"]')->length === 1, 'Public posts expose discovery links and a user-visible embed action.');
foreach ([$embedUrl, '/status/910001/embed'] as $path) {
    [$status, $empty, $headHeaders] = $exportRequest($path, 'HEAD');
    $assert($status === 200 && $empty === '' && isset($headHeaders['etag']), 'oEmbed and frame HEAD requests retain validators with no body.');
}
[$status, $empty] = $exportRequest('/status/910001/embed', 'GET', ['If-None-Match' => $frameHeaders['etag']]);
$assert($status === 304 && $empty === '', 'Unchanged embeds support conditional HTTP revalidation.');
$embedDb->exec("UPDATE content SET body='Updated embed' WHERE id=910001");
[$status, $changed, $changedHeaders] = $exportRequest('/status/910001/embed', 'GET', ['If-None-Match' => $frameHeaders['etag']]);
$assert($status === 200 && str_contains($changed, 'Updated embed') && $changedHeaders['etag'] !== $frameHeaders['etag'], 'Edited posts immediately change the embed representation and validator.');
$embedDb->exec("UPDATE users SET status='inactive' WHERE id=910001");
foreach ([910001, 910002, 910003, 99999999] as $id) {
    foreach (['/status/' . $id . '/embed', '/status/' . $id . '/embed-code', '/oembed?url=' . rawurlencode('http://localhost/status/' . $id)] as $path) {
        [$status, $hidden, $hiddenHeaders] = $exportRequest($path, 'GET', ['If-None-Match' => '*', 'X-Test-Role' => 'admin']);
        $assert($status === 404 && !str_contains($hidden, 'EMBED-SECRET') && !str_contains($hidden, 'Updated embed') && str_contains($hiddenHeaders['cache-control'], 'no-store'), 'Unavailable posts cannot be exposed through an embed or a stale conditional response, even to an administrator.');
    }
}
[$hiddenStatus] = $request('GET', '/api/statuses/910001/embed', null, 'admin');
$assert($hiddenStatus === 404, 'The modal resource respects public visibility even for administrators.');
$embedDb->exec('DELETE FROM content WHERE id IN (910001,910002,910003)');
$embedDb->exec('DELETE FROM users WHERE id IN (910001,910002)');

$insertEmbed = null;
$embedDb = null;
