<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/bootstrap.php';

use TinyCat\Status\Audio;
use TinyCat\Status\Links;
use TinyCat\Status\LinkParser;
use TinyCat\Status\Metadata;
use TinyCat\Status\Providers;
use TinyCat\Bots\FeedParser;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$media = 'https://93.184.216.34/episode.mp3?token=keep';
$direct = LinkParser::fromRaw($media);
$assert($direct['link_type'] === 'audio' && $direct['audio_url'] === $media, 'Direct audio links preserve their playback URL.');
$assert(Links::status_link_metadata_has_content($direct), 'A playable audio source counts as useful metadata even without an article title.');
$assert(Metadata::fetch($media)['audio_url'] === $media, 'Direct audio metadata does not require downloading the recording.');
$assert(Audio::direct('https://93.184.216.34/article') === '', 'An ordinary web page is not an audio file.');
foreach (['http://127.0.0.1/private.mp3', 'file:///private.mp3', 'javascript:alert(1)', 'https://user:password@93.184.216.34/a.mp3'] as $unsafe) {
    $assert(Audio::remote($unsafe) === '', 'Unsafe playback URL accepted.');
}
$rss = '<rss><channel><item><title>Episode</title><guid>ep</guid><link>https://93.184.216.34/episode</link><enclosure type="audio/mpeg" url="' . $media . '"/></item></channel></rss>';
$item = FeedParser::parse($rss)[0];
$assert($item['audio_url'] === $media && $item['image_urls'] === [], 'RSS audio is separate from artwork.');
$atom = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><id>ep</id><title>Episode</title><link rel="enclosure" type="audio/ogg" href="https://93.184.216.34/episode.ogg"/></entry></feed>';
$assert(FeedParser::parse($atom)[0]['audio_url'] === 'https://93.184.216.34/episode.ogg', 'Atom audio enclosures survive.');
$assert(FeedParser::parse($atom)[0]['url'] === 'https://93.184.216.34/episode.ogg', 'Enclosures supply a source link when an episode has no web page.');
$json = json_encode(['version'=>'https://jsonfeed.org/version/1.1', 'title'=>'Podcast', 'items'=>[['id'=>'ep', 'content_text'=>'Episode', 'attachments'=>[['mime_type'=>'audio/mpeg', 'url'=>$media]]]]], JSON_THROW_ON_ERROR);
$assert(FeedParser::parse($json)[0]['audio_url'] === $media, 'JSON Feed audio attachments survive.');
$parseHtml = new ReflectionMethod(Metadata::class, 'parseHtml');
$meta = $parseHtml->invoke(null, '<meta property="og:audio" content="/episode.mp3">', 'https://93.184.216.34/page');
$assert($meta['audio_url'] === 'https://93.184.216.34/episode.mp3' && $meta['link_type'] === 'audio', 'Open Graph audio resolves relative URLs.');
$meta = $parseHtml->invoke(null, '<audio><source src="/episode.ogg"></audio>', 'https://93.184.216.34/page');
$assert($meta['audio_url'] === 'https://93.184.216.34/episode.ogg', 'HTML audio sources are discovered.');
$id = '921ff6c4-997a-3349-8d08-c8a5b759a832';
$url = 'https://www.mujrozhlas.cz/rapi/view/episode/' . $id;
$assert(Providers::structuredRequest($url)['url'] === 'https://api.mujrozhlas.cz/episodes/' . $id, 'Episode links use the mujRozhlas API.');
$assert(Audio::rozhlasId(str_replace('mujrozhlas.cz', 'mujrozhlas.cz.evil.test', $url)) === '', 'Lookalike hosts cannot select a provider.');
$meta = Audio::rozhlasMetadata(['data'=>['type'=>'episode', 'id'=>$id, 'attributes'=>['title'=>'Episode', 'audioLinks'=>[['url'=>$media]]]]], $id);
$assert($meta['audio_url'] === $media && $meta['link_type'] === 'audio', 'Provider audio URLs are extracted.');
$assert(Audio::rozhlasMetadata(['data'=>['type'=>'episode', 'id'=>'wrong']], $id) === [], 'Mismatched episode metadata is rejected.');
$link = Links::status_link_prepare_metadata(LinkParser::fromRaw('https://93.184.216.34/episode'), null, false,
    ['url_hash'=>'', 'title'=>'Episode', 'description'=>'Summary', 'image_urls'=>[], 'audio_url'=>$media]);
$assert($link['audio_url'] === $media && $link['link_type'] === 'audio', 'Feed source audio reaches prepared links without artwork.');
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, normalized_url TEXT, url_hash TEXT, provider TEXT, link_type TEXT, title TEXT, description TEXT, image_url TEXT, video_id TEXT, created_at TEXT, updated_at TEXT)');
$migration = require dirname(__DIR__) . '/migrations/20261031_001_link_audio.php';
$migration($db);
$migration($db);
\TinyCat\Database\Connection::set($db);
$linkId = Links::status_link_upsert($link);
$stored = Links::status_link_find_by_hash($link['url_hash']);
$assert($linkId > 0 && $stored['audio_url'] === $media && $stored['link_type'] === 'audio', 'Audio metadata survives storage and an idempotent migration.');
$cached = Links::status_link_apply_cached_metadata([], $stored, true);
$assert($cached['audio_url'] === $media, 'Cached metadata retains playback URLs.');
$resource = \TinyCat\Status\Resource::link($stored);
$html = \TinyCat\Support\Components::render('status/link', ['link'=>$resource]);
$assert(!str_contains($html, '<audio') && str_contains($html, 'data-audio-url=') && str_contains($html, 'data-status-video-load'), 'Audio cards show the shared play button without creating a player before interaction.');
$assert($resource['card_kind'] === 'audio' && $resource['embed_url'] === '', 'Native audio does not become an iframe.');
$dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
$assert($xpath->query('//div[contains(@class,"status-audio-card")]//button')->length === 1 && $xpath->query('//div[contains(@class,"status-audio-card")]//a[contains(@class,"status-link-card")]')->length === 1, 'Server rendering keeps the summary and shared play button inside one card.');
foreach (['https://open.spotify.com/track/1234567890123456789012', 'https://soundcloud.com/artist/track'] as $provider) {
    $assert(Providers::embedAllowed(Providers::embedUrl(LinkParser::fromRaw($provider))), 'Existing audio provider embeds remain available.');
}
echo "PASS audio discovery, persistence and rendering ({$checks} checks)\n";
