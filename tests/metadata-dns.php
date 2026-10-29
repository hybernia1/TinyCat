<?php
declare(strict_types=1);

namespace TinyCat\Http {
    // Namespace-local doubles keep production URL guards and redirect handling
    // active while avoiding DNS and external HTTP in this fixture.
    function function_exists(string $name): bool
    {
        return $name !== 'curl_init' && \function_exists($name);
    }
    function gethostbynamel(string $host): array|false
    {
        $GLOBALS['dnsCalls'][$host] = ($GLOBALS['dnsCalls'][$host] ?? 0) + 1;
        return $GLOBALS['dnsAnswers'][$host] ?? false;
    }
    function dns_get_record(string $host, int $type): array|false
    {
        $GLOBALS['dns6Calls'][$host] = ($GLOBALS['dns6Calls'][$host] ?? 0) + 1;
        return $GLOBALS['dns6Answers'][$host] ?? [];
    }
    function fopen(string $url, string $mode, bool $includePath = false, mixed $context = null): mixed
    {
        if (!str_starts_with($url, 'https://')) { return \fopen($url, $mode); }
        $GLOBALS['httpCalls'][] = $url;
        $headers = $GLOBALS['responses'][$url] ?? ['HTTP/1.1 404 Not Found'];
        $stream = \fopen('php://temp', 'w+');
        fwrite($stream, $GLOBALS['bodies'][$url] ?? '');
        rewind($stream);
        $GLOBALS['headers'][(int) $stream] = $headers;
        return $stream;
    }
    function stream_get_meta_data(mixed $stream): array
    {
        return array_replace(\stream_get_meta_data($stream), ['wrapper_data' => $GLOBALS['headers'][(int) $stream] ?? []]);
    }
}
namespace {
    require_once __DIR__ . '/cache-isolation.php';
    define('TINYCAT', true);
    require_once dirname(__DIR__) . '/App/autoload.php';
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void {
        $checks++;
        if (!$condition) { throw new RuntimeException($message); }
    };
    $GLOBALS['dnsCalls'] = $GLOBALS['dns6Calls'] = $GLOBALS['httpCalls'] = [];
    $GLOBALS['dnsAnswers'] = ['public.example' => ['93.184.216.34'], 'private.example' => ['10.0.0.1']];
    $GLOBALS['dns6Answers'] = [];
    $GLOBALS['responses'] = [
        'https://public.example/start' => ['HTTP/1.1 302 Found', 'Location: /next'],
        'https://public.example/next' => ['HTTP/1.1 302 Found', 'Location: https://private.example/secret'],
    ];
    \TinyCat\Status\Metadata::fetch('https://public.example/start');
    $assert($GLOBALS['dnsCalls']['public.example'] === 1 && $GLOBALS['dns6Calls']['public.example'] === 1, 'Repeated host checks within one redirect chain must resolve each DNS family once.');
    $assert($GLOBALS['httpCalls'] === ['https://public.example/start', 'https://public.example/next'], 'A redirect to a private host must be rejected before transport.');
    $assert($GLOBALS['dnsCalls']['private.example'] === 1, 'A new redirect host requires its own DNS validation.');

    // A new operation must see a changed address rather than reuse approval.
    $GLOBALS['dnsAnswers']['public.example'] = ['127.0.0.1'];
    \TinyCat\Status\Metadata::fetch('https://public.example/start');
    $assert($GLOBALS['dnsCalls']['public.example'] === 2 && count($GLOBALS['httpCalls']) === 2, 'A later operation re-resolves DNS and rejects an address that became private.');
    $GLOBALS['dnsAnswers']['mixed.example'] = ['93.184.216.34'];
    $GLOBALS['dns6Answers']['mixed.example'] = [['ipv6' => '::1']];
    $assert(!\TinyCat\Status\Metadata::isSafeRemoteUrl('https://mixed.example'), 'A public A record cannot hide a private AAAA record.');
    $assert(!\TinyCat\Status\Metadata::isSafeRemoteUrl('https://missing.example'), 'Missing DNS records remain blocked.');

    $scope = new ReflectionMethod(\TinyCat\Http\Client::class, 'withHostChecks');
    try {
        $scope->invoke(null, static function (): void {
            \TinyCat\Status\Metadata::isSafeRemoteUrl('https://private.example');
            throw new RuntimeException('forced failure');
        });
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'forced failure', 'An operation failure must propagate.');
    }
    $GLOBALS['dnsAnswers']['private.example'] = ['93.184.216.34'];
    $assert(\TinyCat\Status\Metadata::isSafeRemoteUrl('https://private.example'), 'An exception must clear scoped DNS decisions.');
    $assert((new ReflectionProperty(\TinyCat\Http\Client::class, 'hostChecks'))->getValue() === null, 'DNS decisions do not leak beyond their operation.');

    $GLOBALS['dnsAnswers']['public.example'] = ['93.184.216.34'];
    $GLOBALS['responses']['https://public.example/data'] = ['HTTP/1.1 200 OK', 'Content-Type: application/xml'];
    $GLOBALS['bodies']['https://public.example/data'] = '123456';
    $assert(\TinyCat\Http\Client::get('https://public.example/data', 6)['body'] === '123456', 'A body exactly at its byte limit is accepted.');
    $assert(\TinyCat\Http\Client::get('https://public.example/data', 5) === null, 'Oversized documents are rejected.');
    $assert(\TinyCat\Http\Client::get('https://public.example/data', 5, ['truncate' => true])['body'] === '12345', 'HTML truncation is explicit and bounded.');
    $GLOBALS['responses']['https://public.example/redirect'] = ['HTTP/1.1 302 Found', 'Location: /data'];
    $before = count($GLOBALS['httpCalls']);
    $assert(\TinyCat\Http\Client::get('https://public.example/redirect', 6, ['policy' => static fn (string $url): bool => !str_ends_with($url, '/data')]) === null, 'Caller host policies apply before each redirect request.');
    $assert(count($GLOBALS['httpCalls']) === $before + 1, 'A rejected redirect never reaches transport.');
    $assert(\TinyCat\Http\Client::get('https://public.example/redirect', 6, ['redirects' => 0]) === null, 'Redirect limits are enforced.');
    $GLOBALS['responses']['https://public.example/bad'] = ['HTTP/1.1 304 Not Modified'];
    $assert(\TinyCat\Http\Client::get('https://public.example/bad', 6) === null, 'Responses without a successful document are not treated as content.');
    foreach ([
        ['../photo.jpg?x=1&y=2', 'https://public.example/photo.jpg?x=1&y=2'],
        ['?page=2', 'https://public.example/news/feed.xml?page=2'],
        ['//cdn.example/image.jpg', 'https://cdn.example/image.jpg'],
        ['../.', 'https://public.example/'],
        ['../../../photo.jpg', 'https://public.example/photo.jpg'],
        ['javascript:alert(1)', ''],
    ] as [$relative, $expected]) {
        $assert(\TinyCat\Http\Client::absoluteUrl('https://public.example/news/feed.xml', $relative) === $expected, 'Relative URL resolution: ' . $relative);
    }
    $temporary = sys_get_temp_dir() . '/tinycat-download-test-' . bin2hex(random_bytes(6));
    file_put_contents($temporary, 'original');
    try {
        try { \TinyCat\Http\Client::download('https://public.example/data', $temporary, 5); }
        catch (RuntimeException) { /* The existing destination must survive. */ }
        $assert(file_get_contents($temporary) === 'original', 'A failed download preserves its destination.');
        \TinyCat\Http\Client::download('https://public.example/redirect', $temporary, 6);
        $assert(file_get_contents($temporary) === '123456', 'Streaming downloads share redirect handling and publish complete data.');
    } finally { unlink($temporary); }
    $GLOBALS['httpCalls'] = [];
    $GLOBALS['dnsAnswers']['google.com'] = ['93.184.216.34'];
    $GLOBALS['dnsAnswers']['maps.google.com'] = ['93.184.216.34'];
    $static = \TinyCat\Status\Metadata::fetch('https://google.com');
    $assert($static['title'] === 'Google' && $GLOBALS['httpCalls'] === [] && !isset($GLOBALS['dnsCalls']['google.com']), 'Static service cards need neither homepage requests nor DNS lookups.');
    $GLOBALS['dnsAnswers']['open.spotify.com'] = ['93.184.216.34'];
    $spotify = 'https://open.spotify.com/track/0Lr4kGOYn9l83EjuK6cZFQ';
    $spotifyEndpoint = \TinyCat\Status\Providers::oembedUrl($spotify);
    $GLOBALS['responses'][$spotifyEndpoint] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
    $GLOBALS['bodies'][$spotifyEndpoint] = json_encode(['title' => 'Track title', 'html' => '<script>evil()</script>']);
    $metadata = \TinyCat\Status\Metadata::fetch($spotify);
    $assert($metadata['title'] === 'Track title' && !isset($metadata['html']) && $GLOBALS['httpCalls'] === [$spotifyEndpoint], 'Known providers use only JSON APIs and never expose their embed markup.');
    $GLOBALS['httpCalls'] = [];
    $page = 'https://public.example/blog/first';
    $pageTwo = 'https://public.example/blog/second';
    $discovery = 'https://public.example/oembed?url=' . rawurlencode($page);
    $GLOBALS['responses'][$page] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $GLOBALS['bodies'][$page] = '<html><head><title>HTML title</title><meta name="description" content="HTML summary"><link rel="alternate" type="application/json+oembed" href="' . htmlspecialchars($discovery, ENT_QUOTES) . '"></head></html>';
    $endpoint = 'https://public.example/oembed?format=json&url=' . rawurlencode($page);
    $endpointTwo = 'https://public.example/oembed?format=json&url=' . rawurlencode($pageTwo);
    foreach ([$endpoint, $endpointTwo] as $value) {
        $GLOBALS['responses'][$value] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
        $GLOBALS['bodies'][$value] = json_encode(['title' => 'API title', 'description' => 'API description', 'thumbnail_url' => 'https://public.example/photo.jpg']);
    }
    $metadata = \TinyCat\Status\Metadata::fetch($page);
    $assert($metadata['title'] === 'HTML title' && $metadata['description'] === 'HTML summary', 'First discovery preserves HTML metadata and fills missing fields from oEmbed.');
    $GLOBALS['httpCalls'] = [];
    $metadata = \TinyCat\Status\Metadata::fetch($pageTwo);
    $assert($metadata['title'] === 'API title' && $GLOBALS['httpCalls'] === [$endpointTwo], 'A complete discovered endpoint avoids HTML on the next article.');
    $GLOBALS['responses'][$endpointTwo] = ['HTTP/1.1 500 Error'];
    $GLOBALS['responses'][$pageTwo] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $GLOBALS['bodies'][$pageTwo] = '<html><head><title>Fallback</title></head></html>';
    $GLOBALS['httpCalls'] = [];
    $metadata = \TinyCat\Status\Metadata::fetch($pageTwo);
    $assert($metadata['title'] === 'Fallback' && $GLOBALS['httpCalls'] === [$endpointTwo, $pageTwo], 'Unavailable discovered APIs fall back to bounded HTML fetching.');
    $badDocument = \Dom\HTMLDocument::createFromString('<link rel="alternate" type="application/json+oembed" href="http://127.0.0.1/private">', LIBXML_NOERROR);
    $assert(\TinyCat\Status\Providers::discover($badDocument, 'https://public.example') === '', 'Discovery cannot introduce a private endpoint.');
    $GLOBALS['dnsAnswers']['wp.example'] = ['93.184.216.34'];
    $wpFirst = 'https://wp.example/blog/first';
    $wpSecond = 'https://wp.example/blog/second';
    $GLOBALS['responses'][$wpFirst] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $GLOBALS['bodies'][$wpFirst] = '<html><head><title>First article</title><meta name="description" content="First summary"><meta property="og:image" content="https://wp.example/first.jpg"><link rel="https://api.w.org/" href="https://wp.example/blog/wp-json/"><link rel="alternate" type="application/json" href="https://wp.example/blog/wp-json/wp/v2/posts/22"></head></html>';
    $GLOBALS['httpCalls'] = [];
    \TinyCat\Status\Metadata::fetch($wpFirst);
    $assert($GLOBALS['httpCalls'] === [$wpFirst], 'First WordPress discovery needs no speculative API probes.');
    $single = \TinyCat\Status\Providers::wordpressUrl($wpFirst);
    $assert(str_starts_with($single, 'https://wp.example/blog/wp-json/wp/v2/posts/22?'), 'A declared single-post API is preferred over slug lookup.');
    $wpEndpoint = \TinyCat\Status\Providers::wordpressUrl($wpSecond);
    $post = ['id' => 23, 'link' => $wpSecond, 'title' => ['rendered' => 'API <em>title</em>'], 'excerpt' => ['rendered' => '<p>Actual article excerpt</p>'], '_embedded' => ['wp:featuredmedia' => [['source_url' => 'https://wp.example/cover.jpg']]]];
    $GLOBALS['responses'][$wpEndpoint] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
    $GLOBALS['bodies'][$wpEndpoint] = json_encode([$post]);
    $GLOBALS['httpCalls'] = [];
    $metadata = \TinyCat\Status\Metadata::fetch($wpSecond);
    $assert($metadata['title'] === 'API title' && $metadata['description'] === 'Actual article excerpt' && $metadata['image_url'] === 'https://wp.example/cover.jpg' && $GLOBALS['httpCalls'] === [$wpEndpoint], 'Complete WordPress REST metadata avoids HTML and sanitizes rendered text.');
    $assert($metadata['provider'] === 'wordpress' && $metadata['link_type'] === 'web', 'REST discovery identifies WordPress independently of content type.');
    $assert(\TinyCat\Status\Providers::taxonomy(['normalized_url' => $wpSecond]) === ['provider' => 'wordpress', 'link_type' => 'web'], 'Known WordPress origins are identified during writes without new requests.');
    unset($post['_embedded']);
    $GLOBALS['bodies'][$wpEndpoint] = json_encode([$post]);
    $GLOBALS['responses'][$wpSecond] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $GLOBALS['bodies'][$wpSecond] = '<html><head><title>HTML title</title><meta name="description" content="HTML excerpt"><meta property="og:image" content="https://wp.example/default-og.jpg"></head></html>';
    $GLOBALS['httpCalls'] = [];
    $metadata = \TinyCat\Status\Metadata::fetch($wpSecond);
    $assert($metadata['description'] === 'Actual article excerpt' && $metadata['image_url'] === 'https://wp.example/default-og.jpg' && $GLOBALS['httpCalls'] === [$wpEndpoint, $wpSecond], 'Sparse REST metadata is supplemented from HTML without losing its excerpt.');
    $post['link'] = 'https://wp.example/other/second';
    $GLOBALS['bodies'][$wpEndpoint] = json_encode([$post]);
    $metadata = \TinyCat\Status\Metadata::fetch($wpSecond);
    $assert($metadata['title'] === 'HTML title' && $metadata['description'] === 'HTML excerpt', 'A matching slug with a different canonical URL cannot supply article metadata.');
    $idEndpoint = \TinyCat\Status\Providers::wordpressUrl('https://wp.example/blog/?p=23');
    $assert(str_starts_with($idEndpoint, 'https://wp.example/blog/wp-json/wp/v2/posts/23?'), 'Numeric post permalinks use the single-post REST API.');
    $GLOBALS['dnsAnswers']['query.example'] = ['93.184.216.34'];
    $queryDocument = \Dom\HTMLDocument::createFromString('<link rel="https://api.w.org/" href="https://query.example/?rest_route=/">', LIBXML_NOERROR);
    \TinyCat\Status\Providers::discover($queryDocument, 'https://query.example/article');
    parse_str((string) parse_url(\TinyCat\Status\Providers::wordpressUrl('https://query.example/article'), PHP_URL_QUERY), $restQuery);
    $assert($restQuery['rest_route'] === '/wp/v2/posts' && $restQuery['slug'] === 'article', 'Query-based REST roots retain their routing format.');
    $GLOBALS['dnsAnswers']['www.facebook.com'] = ['93.184.216.34'];
    $GLOBALS['dnsAnswers']['scontent.xx.fbcdn.net'] = ['93.184.216.34'];
    $fbPost = 'https://www.facebook.com/ExamplePage/posts/123';
    $fbPlugin = \TinyCat\Status\Providers::scrapeUrl($fbPost);
    $GLOBALS['responses'][$fbPlugin] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $GLOBALS['bodies'][$fbPlugin] = (string) file_get_contents(__DIR__ . '/fixtures/facebook-post.html');
    $GLOBALS['httpCalls'] = [];
    $metadata = \TinyCat\Status\Metadata::fetch($fbPost);
    $assert($metadata['title'] === 'Testovací stránka' && $metadata['description'] === 'První odstavec příspěvku. Druhý odstavec.', 'Facebook previews extract author and message without scripts or expand controls.');
    $assert($metadata['image_url'] === 'https://scontent.xx.fbcdn.net/cover.jpg?key=a&part=b', 'Facebook previews select the post photo instead of avatars, icons and unrelated images.');
    $assert($GLOBALS['httpCalls'] === [$fbPlugin], 'Facebook extraction fetches only its official plugin on the server.');
    $staticFb = ['normalized_url' => $fbPost, 'title' => 'Facebook', 'description' => \TinyCat\Status\Providers::classify($fbPost)['description'], 'updated_at' => date('Y-m-d H:i:s')];
    $assert(!\TinyCat\Status\Links::status_link_metadata_fresh($staticFb), 'Old static Facebook cards can refresh into real post previews.');
    $GLOBALS['bodies'][$fbPlugin] = '<html><head><title>Log in to Facebook</title></head><body>Log in</body></html>';
    $assert(\TinyCat\Status\Metadata::fetch($fbPost) === [], 'Unavailable or login-only plugin pages do not become successful metadata.');
    $fbVideo = 'https://www.facebook.com/reel/123456789';
    $videoPlugin = \TinyCat\Status\Providers::scrapeUrl($fbVideo);
    $GLOBALS['responses'][$videoPlugin] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $GLOBALS['bodies'][$videoPlugin] = '<div data-testid="post_message"><p>Video caption.</p></div><img src="https://static.xx.fbcdn.net/blank.gif" width="500" height="281">';
    $GLOBALS['responses'][$fbVideo] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $GLOBALS['bodies'][$fbVideo] = (string) file_get_contents(__DIR__ . '/fixtures/facebook-video.html');
    $GLOBALS['httpCalls'] = [];
    $metadata = \TinyCat\Status\Metadata::fetch($fbVideo);
    $assert($metadata['description'] === 'Video caption.' && $metadata['image_url'] === 'https://scontent.xx.fbcdn.net/poster.jpg?key=one&part=two', 'Video previews supplement the caption with the real poster instead of Facebook placeholder GIFs.');
    $assert($GLOBALS['httpCalls'] === [$videoPlugin, $fbVideo], 'Video poster fallback remains bounded server-side work.');
    if (extension_loaded('gd') && function_exists('imagewebp')) {
        $imageSource = imagecreatetruecolor(1000, 600);
        ob_start();
        imagepng($imageSource);
        $imageBody = ob_get_clean();
        imagedestroy($imageSource);
        $imageUrl = 'https://public.example/card-geometry-' . bin2hex(random_bytes(6)) . '.png';
        $GLOBALS['responses'][$imageUrl] = ['HTTP/1.1 200 OK', 'Content-Type: image/png'];
        $GLOBALS['bodies'][$imageUrl] = $imageBody;
        $localImages = [];
        try {
            foreach (['card' => [184, 172], 'social' => [800, 480], 'video' => [900, 540], 'reel' => [540, 324]] as $variant => $expected) {
                $local = \TinyCat\Status\Links::status_link_image_cache($imageUrl, $variant);
                $localImages[] = $local;
                $info = getimagesize(\TinyCat\Support\Paths::basePath(ltrim($local, '/')));
                $assert([$info[0], $info[1]] === $expected, 'Preview geometry preserves the intended variant: ' . $variant);
            }
            $assert(count(array_unique($localImages)) === 4, 'Different preview sizes never reuse the wrong cached geometry.');
        } finally {
            foreach ($localImages as $local) {
                if (\TinyCat\Status\Links::status_link_image_exists($local)) { unlink(\TinyCat\Support\Paths::basePath(ltrim($local, '/'))); }
            }
        }
    }
    $GLOBALS['dnsAnswers']['x.com'] = ['93.184.216.34'];
    $GLOBALS['dnsAnswers']['cdn.syndication.twimg.com'] = ['93.184.216.34'];
    $GLOBALS['dnsAnswers']['publish.twitter.com'] = ['93.184.216.34'];
    $GLOBALS['dnsAnswers']['pbs.twimg.com'] = ['93.184.216.34'];
    $xUrl = 'https://x.com/example/status/123456789/photo/1';
    $structured = \TinyCat\Status\Providers::structuredRequest($xUrl);
    $tweet = json_decode((string) file_get_contents(__DIR__ . '/fixtures/x-post.json'), true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['responses'][$structured['url']] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
    $GLOBALS['bodies'][$structured['url']] = json_encode($tweet);
    $GLOBALS['httpCalls'] = [];
    $metadata = \TinyCat\Status\Metadata::fetch($xUrl);
    $assert($metadata['image_url'] === 'https://pbs.twimg.com/media/test-photo.jpg' && $metadata['title'] === 'Test Author', 'X images come from the original post, not its author avatar.');
    $assert($metadata['description'] === 'Text <b>bez HTML</b>', 'X text remains literal and its attached-media URL is removed.');
    $assert($GLOBALS['httpCalls'] === [$structured['url']], 'Rich widget data avoids a redundant oEmbed request.');
    $assert(\TinyCat\Status\Providers::needsMetadataRefresh(['normalized_url' => $xUrl, 'image_url' => '']), 'An old image-less record remains refreshable until the discovered image is persisted.');
    unset($tweet['mediaDetails']);
    $GLOBALS['bodies'][$structured['url']] = json_encode($tweet);
    $metadata = \TinyCat\Status\Metadata::fetch($xUrl);
    $assert(empty($metadata['image_url']), 'Quoted post media is not substituted for a post without its own photo.');
    $assert(!\TinyCat\Status\Providers::needsMetadataRefresh(['normalized_url' => $xUrl, 'image_url' => '']), 'Confirmed text-only posts do not repeatedly request a missing photo.');
    $GLOBALS['bodies'][$structured['url']] = json_encode(['__typename' => 'Tweet', 'id_str' => '999', 'photos' => [['url' => 'https://pbs.twimg.com/media/wrong.jpg']]]);
    $oembed = \TinyCat\Status\Providers::oembedUrl($xUrl);
    $GLOBALS['responses'][$oembed] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
    $GLOBALS['bodies'][$oembed] = json_encode(['author_name' => 'Fallback author', 'html' => '<blockquote><p>Fallback text</p></blockquote>']);
    $metadata = \TinyCat\Status\Metadata::fetch($xUrl);
    $assert($metadata['title'] === 'Fallback text' && empty($metadata['image_url']), 'Wrong-ID or unavailable widget data falls back to oEmbed without mismatched images.');
    $GLOBALS['dnsAnswers']['google.com'] = ['93.184.216.34'];
    $mapUrl = 'https://google.com/maps/place/Charles+Bridge/@50.0981848,14.4506479,14z/data=!3d50.0864771!4d14.4114366';
    $GLOBALS['responses'][$mapUrl] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
    $mapImage = 'https://maps.google.com/maps/api/staticmap?center=50.0981848,14.4506479&zoom=13&size=900x900&markers=color%3Ared%7C50.0864771%2C14.4114366&signature=example';
    foreach ([
        [$mapImage, true],
        [str_replace('50.0864771%2C14.4114366', '50.0981848%2C14.4506479', $mapImage), false],
        [str_replace('center=50.0981848,14.4506479&zoom=13&', '', $mapImage), true],
        [str_replace('markers=color%3Ared%7C50.0864771%2C14.4114366&', '', $mapImage), false],
        [str_replace('markers=color%3Ared%7C50.0864771%2C14.4114366', 'markers=50.1%2C14.4&markers=size%3Amid%7C50.0864771%2C14.4114366&markers=50.2%2C14.5', $mapImage), true],
        [str_replace('markers=color%3Ared%7C50.0864771%2C14.4114366', 'markers=color%3Ared%7C50.1%2C14.4%7C50.08648%2C14.41144', $mapImage), true],
        [str_replace('markers=color%3Ared%7C50.0864771%2C14.4114366', 'markers=Charles+Bridge', $mapImage), false],
        [str_replace('maps.google.com', 'maps.google.com.evil.example', $mapImage), false],
    ] as [$imageUrl, $accepted]) {
        $GLOBALS['bodies'][$mapUrl] = '<html><head><meta property="og:title" content="Google Maps"><meta property="og:description" content="Generic maps text"><meta property="og:image" content="' . htmlspecialchars($imageUrl, ENT_QUOTES) . '"></head></html>';
        $metadata = \TinyCat\Status\Metadata::fetch($mapUrl);
        $assert($metadata['title'] === 'Charles Bridge' && $metadata['description'] === '50.0864771, 14.4114366', 'Map metadata retains the requested place and exact coordinates rather than generic OG text.');
        $assert(!empty($metadata['image_url']) === $accepted, 'Map thumbnails require a matching numeric marker instead of a matching viewport center.');
        if ($accepted) { $assert($metadata['image_url'] === $imageUrl, 'Marker verification does not modify the signed image URL.'); }
    }
    $before = count($GLOBALS['httpCalls']);
    $plainMap = \TinyCat\Status\Metadata::fetch('https://google.com/maps/place/Prague');
    $assert($plainMap['title'] === 'Prague' && count($GLOBALS['httpCalls']) === $before, 'Maps without an exact position use local URL metadata without a pointless image request.');
    $cachedMap = \TinyCat\Status\LinkParser::fromRaw($mapUrl) + ['updated_at' => gmdate('Y-m-d H:i:s')];
    $assert(\TinyCat\Status\Links::status_link_metadata_fresh($cachedMap), 'Image-free map metadata uses the normal cache TTL rather than refetching on every save.');
    $GLOBALS['responses'][$mapUrl] = ['HTTP/1.1 302 Found', 'Location: https://consent.google.com/ml'];
    $GLOBALS['dnsAnswers']['consent.google.com'] = ['93.184.216.34'];
    $metadata = \TinyCat\Status\Metadata::fetch($mapUrl);
    $assert($metadata['title'] === 'Charles Bridge' && $metadata['description'] === '50.0864771, 14.4114366' && empty($metadata['image_url']), 'Consent redirects retain useful URL metadata without scraping a consent page.');
    require __DIR__ . '/bots/wp-feed-cases.php';
    echo "PASS scoped DNS, provider metadata and redirect guards ({$checks} checks)\n";
}
