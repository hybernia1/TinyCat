<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';

use TinyCat\Status\LinkParser;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
foreach ([
    'https://example.com/search?tag=a&tag=b' => 'https://example.com/search?tag=a&tag=b',
    'https://example.com/?a.b=1&a+b=2&a_b=3' => 'https://example.com?a.b=1&a+b=2&a_b=3',
    'https://example.com/?flag&empty=&value=a%20b&value=a+b' => 'https://example.com?flag&empty=&value=a%20b&value=a+b',
    'https://example.com/?z=2&a=1&items[]=x&items[]=y' => 'https://example.com?z=2&a=1&items[]=x&items[]=y',
    'https://example.com/?ref=chapter&feature=search&si=token&campaign=story&spm=part' => 'https://example.com?ref=chapter&feature=search&si=token&campaign=story&spm=part',
    'https://example.com/?utm_source=news&tag=a&FBCLID=123&tag=b&%75tm_medium=email' => 'https://example.com?tag=a&tag=b',
    'https://example.com/?utm_source=news&gclid=123' => 'https://example.com',
] as $input => $expected) {
    $link = LinkParser::fromRaw($input);
    $assert(($link['normalized_url'] ?? '') === $expected, 'Query semantics changed: ' . $input);
    $assert(LinkParser::fromRaw($expected)['normalized_url'] === $expected, 'Normalization is not idempotent: ' . $input);
}
foreach ([
    ['https://example.com/wiki/Title_(detail)', 'https://example.com/wiki/Title_(detail)', ''],
    ['https://example.com/wiki/Title_(detail)).', 'https://example.com/wiki/Title_(detail)', ').'],
    ['https://example.com/a[b]{c}!', 'https://example.com/a[b]{c}', '!'],
    ['https://example.com/a(b(c))).', 'https://example.com/a(b(c))', ').'],
    ['https://example.com/path?!', 'https://example.com/path', '?!'],
    ['https://example.com/a%29).', 'https://example.com/a%29', ').'],
] as [$raw, $url, $tail]) {
    $assert(LinkParser::splitTail($raw) === [$url, $tail], 'URL punctuation split failed: ' . $raw);
    $assert((LinkParser::extract('See (' . $raw)[0]['normalized_url'] ?? '') === $url, 'Text extraction lost URL brackets: ' . $raw);
}
$links = LinkParser::extract(str_repeat('https://example.com/a?tag=1&tag=2 ', 1000) . 'https://example.com/b', 2);
$assert(count($links) === 2 && $links[1]['normalized_url'] === 'https://example.com/b', 'Repeated URLs consume the unique-link limit.');
$links = LinkParser::extract('https://example.com/a?utm_source=one https://example.com/a?utm_source=two');
$assert(count($links) === 1, 'Tracking variants no longer deduplicate.');
foreach ([
    ['http://www.twitter.com/Example/status/123?s=20&t=tracking', 'https://x.com/i/web/status/123'],
    ['https://x.com/Other/status/123/photo/2', 'https://x.com/i/web/status/123'],
    ['https://www.google.com/maps/place/Prague?entry=ttu&g_ep=abc&query_place_id=place', 'https://google.com/maps/place/Prague?query_place_id=place'],
    ['HTTPS://WWW.Example.COM:443/path/?utm_source=feed#section', 'https://www.example.com/path/#section'],
    ['https://example.com?b=2&a=1&a=3', 'https://example.com?b=2&a=1&a=3'],
    ['https://example.com?utm_source=required&X-Amz-Signature=abc', 'https://example.com?utm_source=required&X-Amz-Signature=abc'],
    ['https://example.com/#/one', 'https://example.com#/one'],
    ['https://example.com/#0', 'https://example.com#0'],
] as [$input, $expected]) {
    $canonical = LinkParser::fromRaw($input);
    $assert(($canonical['normalized_url'] ?? '') === $expected, 'Canonical URL: ' . $input);
    $assert(LinkParser::fromRaw($expected)['url_hash'] === $canonical['url_hash'], 'Canonicalization is idempotent: ' . $input);
}
foreach (['https://user:password@example.com/a', "https://example.com/a\nb", 'https://example.com/' . str_repeat('a', 2049)] as $invalid) {
    $assert(LinkParser::fromRaw($invalid) === null, 'Invalid URLs are rejected instead of silently rewritten.');
}
$assert(LinkParser::fromRaw('https://example.com/a')['url_hash'] !== LinkParser::fromRaw('https://www.example.com/a')['url_hash'], 'Unknown www hosts remain distinct.');
$assert(LinkParser::fromRaw('https://example.com/#/one')['url_hash'] !== LinkParser::fromRaw('https://example.com/#/two')['url_hash'], 'Fragment routes remain distinct.');
$data = \TinyCat\Status\Links::status_link_data(['normalized_url' => 'https://twitter.com/Example/status/123?s=20', 'url_hash' => 'untrusted']);
$assert($data['normalized_url'] === 'https://x.com/i/web/status/123' && $data['url_hash'] === hash('sha256', $data['normalized_url']), 'Persistence derives identity from the canonical URL instead of trusting a supplied hash.');
$assert(\TinyCat\Status\Links::status_link_data(['normalized_url' => 'https://example.com/' . str_repeat('a', 2049), 'url_hash' => 'untrusted']) === [], 'Persistence rejects oversized URLs instead of truncating them.');
$mapUrl = "https://www.google.com/maps/place/50%C2%B006'18.5%22N+14%C2%B025'24.3%22E/@50.105135,14.423416,17z/data=!3m1!4b1?entry=ttu";
$map = LinkParser::extract($mapUrl)[0];
$assert($map['normalized_url'] === str_replace(['www.', '?entry=ttu'], '', $mapUrl), 'Map coordinate apostrophes do not split URLs and sharing parameters are removed.');
$assert($map['title'] === urldecode("50%C2%B006'18.5%22N+14%C2%B025'24.3%22E") && $map['description'] === '', 'Coordinate labels remain readable without treating the viewport as a place position.');
$assert(count(LinkParser::extract("'https://example.com/test' text")) === 1 && LinkParser::extract("'https://example.com/test' text")[0]['normalized_url'] === 'https://example.com/test', 'Surrounding quotes remain outside URLs.');
$mapResource = (new ReflectionMethod(\TinyCat\Status\Resource::class, 'link'))->invoke(null, $map);
$assert($mapResource['display_url'] === 'google.com/maps' && $mapResource['provider_name'] === 'Google Maps', 'Map cards display a compact address and Maps branding.');
$namedMap = \TinyCat\Status\Providers::googleMap('https://google.com/maps/place/Karl%C5%AFv+most/@50.0981848,14.4506479,14z/data=!3d50.0864771!4d14.4114366');
$assert($namedMap['title'] === 'Karlův most' && $namedMap['description'] === '50.0864771, 14.4114366', 'Named places use the actual place coordinates rather than the viewport center.');
$assert(\TinyCat\Status\Providers::googleMap('https://google.com/maps/search/50.1,+14.4')['title'] === '50.1, 14.4' && \TinyCat\Status\Providers::googleMap('https://google.com/maps/search/50.1,+14.4')['description'] === '', 'Coordinate searches display their position once.');
$assert(\TinyCat\Status\Providers::googleMap('https://google.com/maps/place/Test/data=!3d91!4d200')['description'] === '', 'Invalid coordinates do not become map metadata.');
$assert(\TinyCat\Status\Providers::googleMap('https://google.com.evil.example/maps/place/test') === null, 'Map rules reject lookalike hosts.');
$video = LinkParser::fromRaw('https://youtu.be/abc123?si=share');
$assert($video['normalized_url'] === 'https://www.youtube.com/watch?v=abc123', 'Video URLs no longer use the canonical provider URL.');
$assert(LinkParser::fromRaw('https://example.com/my-article')['title'] === 'my article', 'Title fallback changed while reusing parsed URL parts.');
$assert(LinkParser::fromRaw('https://example.com/my-article')['display_url'] === 'example.com/my-article', 'Display URL changed while reusing parsed URL parts.');

foreach (['https://www.youtube.com/shorts/abc123' => 'youtube', 'https://player.vimeo.com/video/987654' => 'vimeo', 'https://dai.ly/xyz123' => 'dailymotion', 'https://facebook.com/example/posts/123' => 'facebook', 'https://x.com/example/status/123' => 'x', 'https://google.com' => 'google'] as $url => $provider) {
    $assert(LinkParser::fromRaw($url)['provider'] === $provider, 'Central provider classification: ' . $url);
}
$assert(LinkParser::fromRaw('https://facebook.com.evil.example/article')['provider'] === '', 'Service suffixes do not match lookalike domains.');
$assert(\TinyCat\Status\Providers::oembedUrl('https://facebook.com/example') === '', 'Services without useful public metadata remain local cards.');
$assert(str_starts_with(\TinyCat\Status\Providers::oembedUrl('https://open.spotify.com/track/0Lr4kGOYn9l83EjuK6cZFQ'), 'https://open.spotify.com/oembed?'), 'Supported Spotify URLs go directly to oEmbed.');
$assert(\TinyCat\Status\Providers::embedUrl(['provider' => 'youtube', 'video_id' => 'abc123']) === 'https://www.youtube.com/embed/abc123', 'Player construction belongs to provider rules.');
$assert(!\TinyCat\Status\Providers::embedAllowed('http://www.youtube.com/embed/abc123') && !\TinyCat\Status\Providers::embedAllowed('https://www.youtube.com.evil.example/embed/abc123'), 'Player origins require HTTPS and an exact allowed host.');
$resource = (new ReflectionMethod(\TinyCat\Status\Resource::class, 'link'))->invoke(null, ['normalized_url' => 'https://youtube.com/watch?v=abc123', 'provider' => 'youtube', 'video_id' => 'abc123', 'image_url' => 'https://i.ytimg.com/vi/abc123/hqdefault.jpg']);
$assert($resource['image_url'] === '' && $resource['thumbnail_url'] === 'https://i.ytimg.com/vi/abc123/mqdefault.jpg' && $resource['thumbnail_mobile_url'] === 'https://i.ytimg.com/vi/abc123/default.jpg' && $resource['embed_allowed'] === true, 'YouTube renders small mobile and medium desktop thumbnails directly from the verified video URL.');
$assert(\TinyCat\Status\Providers::youtubeThumbnails('https://youtube.com.evil.example/watch?v=abc123') === [], 'Lookalike hosts cannot produce YouTube thumbnails.');
$assert(\TinyCat\Status\Providers::youtubeThumbnails('https://www.youtube.com/@channel') === [], 'Channel pages are not video thumbnails.');
$assert(\TinyCat\Status\Providers::imageCandidates($video + ['image_url' => 'https://example.com/large.jpg']) === [], 'YouTube artwork never enters the download candidate queue.');
$youtubeSource = ['url_hash' => $video['url_hash'], 'title' => 'Feed title', 'description' => 'Feed excerpt', 'image_urls' => ['https://example.com/large.jpg']];
$preparedVideo = \TinyCat\Status\Links::status_link_prepare_metadata($video, null, true, $youtubeSource);
$assert($preparedVideo['image_url'] === '' && $preparedVideo['title'] === 'Feed title', 'New feed videos keep metadata without fetching their artwork.');
$assert(\TinyCat\Status\Links::status_link_data($video + ['image_url' => '/uploads/links/2026/11/' . str_repeat('a', 40) . '.webp'])['image_url'] === '', 'YouTube persistence cannot retain a stale local thumbnail path.');
$fbPost = 'https://www.facebook.com/ExamplePage/posts/pfbidExample123';
$fbPlugin = 'https://www.facebook.com/plugins/post.php?href=' . rawurlencode($fbPost) . '&width=50';
$assert(LinkParser::fromRaw($fbPlugin)['normalized_url'] === $fbPost && LinkParser::fromRaw($fbPlugin)['url_hash'] === LinkParser::fromRaw($fbPost)['url_hash'], 'Facebook plugin URLs unwrap to the same canonical post.');
$assert(str_contains(\TinyCat\Status\Providers::scrapeUrl($fbPost), 'width=500'), 'Facebook server previews use the bounded public post plugin.');
$assert(\TinyCat\Status\Providers::scrapeUrl('https://facebook.com/plugins/post.php?href=http%3A%2F%2F127.0.0.1%2Fprivate') === '', 'Plugin wrappers cannot introduce arbitrary target hosts.');
$assert(\TinyCat\Status\Providers::scrapeUrl('https://facebook.com/plugins/post.php?href=' . rawurlencode($fbPlugin)) === '', 'Nested plugin wrappers are rejected.');
$assert(!\TinyCat\Status\Providers::canFetchHtml('https://www.facebook.com/login') && \TinyCat\Status\Providers::canFetchHtml(\TinyCat\Status\Providers::scrapeUrl($fbPost)), 'Only a validated Facebook plugin endpoint is allowed for scraping.');
$assert(LinkParser::fromRaw('https://example.com/article')['link_type'] === 'web', 'Unknown sites have a web type and no fabricated provider.');
$assert(LinkParser::fromRaw('https://open.spotify.com/track/0Lr4kGOYn9l83EjuK6cZFQ')['link_type'] === 'audio', 'Audio content is classified independently from its provider.');
$assert(\TinyCat\Status\Providers::taxonomy(['normalized_url' => 'https://example.com/article', 'provider' => 'web', 'link_type' => 'link']) === ['provider' => '', 'link_type' => 'web'], 'Legacy classification normalizes on write.');
foreach ([
    'https://facebook.com/reel/123456789/?mibextid=share' => 'https://www.facebook.com/reel/123456789',
    'https://m.facebook.com/watch/?v=123456789&fbclid=tracking' => 'https://www.facebook.com/watch/?v=123456789',
    'https://www.facebook.com/ExamplePage/videos/123456789/' => 'https://www.facebook.com/watch/?v=123456789',
    'https://www.facebook.com/video.php?v=123456789' => 'https://www.facebook.com/watch/?v=123456789',
    'https://www.facebook.com/ExamplePage/videos/my-video/123456789/' => 'https://www.facebook.com/watch/?v=123456789',
] as $url => $canonical) {
    $link = LinkParser::fromRaw($url);
    $assert($link['provider'] === 'facebook' && $link['link_type'] === 'video' && $link['video_id'] === '123456789' && $link['normalized_url'] === $canonical, 'Facebook video URL recognition: ' . $url);
    $embed = \TinyCat\Status\Providers::embedUrl($link);
    $assert(str_starts_with($embed, 'https://www.facebook.com/plugins/video.php?') && \TinyCat\Status\Providers::embedAllowed($embed), 'Facebook videos use the approved click-to-play endpoint.');
}
$assert(!\TinyCat\Status\Providers::embedAllowed('https://www.facebook.com/plugins/post.php?href=' . rawurlencode($fbPost)), 'Facebook post widgets are not permitted as video players.');
$assert(!\TinyCat\Status\Providers::embedAllowed('https://www.facebook.com/plugins/video.php?href=' . rawurlencode('https://evil.example/video')), 'Facebook video widgets require a valid Facebook video target.');
$reel = LinkParser::fromRaw('https://facebook.com/reel/123456789');
$reelResource = (new ReflectionMethod(\TinyCat\Status\Resource::class, 'link'))->invoke(null, $reel);
$assert($reelResource['card_kind'] === 'reel' && $reelResource['link_type'] === 'video' && $reelResource['embed_allowed'], 'Reels retain their portrait card variant while remaining video content.');
$assert(\TinyCat\Status\Providers::needsMetadataRefresh(['normalized_url' => $reel['normalized_url'], 'provider' => 'facebook', 'link_type' => 'web', 'title' => 'Old card']), 'Previously generic Facebook reel records refresh into video metadata on the next write.');
$assert(\TinyCat\Status\Providers::presentation(['provider' => 'facebook', 'link_type' => 'web'])['card_kind'] === 'social', 'Social posts use their own card layout.');
$assert(\TinyCat\Status\Providers::presentation(['provider' => 'spotify', 'link_type' => 'audio'])['card_kind'] === 'audio', 'Audio content uses its own card layout.');
$soundcloud = LinkParser::fromRaw('https://soundcloud.com/forss/flickermood');
$soundcloudResource = (new ReflectionMethod(\TinyCat\Status\Resource::class, 'link'))->invoke(null, $soundcloud);
$assert($soundcloudResource['link_type'] === 'audio' && $soundcloudResource['card_kind'] === 'audio' && $soundcloudResource['embed_allowed'], 'SoundCloud remains audio content with a supported click-to-play widget.');
$assert($soundcloudResource['embed_origin'] === 'https://w.soundcloud.com' && $soundcloudResource['embed_height'] === 166 && $soundcloudResource['embed_autoplay_param'] === 'auto_play', 'SoundCloud player options belong to the provider.');
$assert(\TinyCat\Status\Providers::presentation(['provider' => 'soundcloud', 'link_type' => 'audio', 'normalized_url' => 'https://soundcloud.com/artist/sets/album'])['embed_height'] === 166, 'SoundCloud playlists use the compact player height.');
$assert(\TinyCat\Status\Providers::presentation(['provider' => 'spotify', 'link_type' => 'audio'])['embed_height'] === 152, 'Spotify uses its compact player height.');
parse_str((string) parse_url($soundcloudResource['embed_url'], PHP_URL_QUERY), $compactSoundcloud);
$assert($compactSoundcloud['show_artwork'] === 'false' && $compactSoundcloud['show_comments'] === 'false' && $compactSoundcloud['sharing'] === 'false', 'SoundCloud avoids duplicate artwork, timed comments and sharing controls.');
foreach (['https://w.soundcloud.com/player/?url=http%3A%2F%2F127.0.0.1%2Fprivate', 'https://w.soundcloud.com/player/api.js?url=https%3A%2F%2Fsoundcloud.com%2Fforss%2Fflickermood', 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com.evil.example%2Fartist%2Ftrack'] as $invalidPlayer) {
    $assert(!\TinyCat\Status\Providers::embedAllowed($invalidPlayer), 'SoundCloud widgets only accept recognized SoundCloud audio targets.');
}

echo "PASS link query semantics, punctuation and deduplication ({$checks} checks)\n";
