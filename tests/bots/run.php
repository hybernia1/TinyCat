<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';


$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$invoke = static fn (string $name, mixed ...$args): mixed => (new ReflectionMethod(\TinyCat\Bots\Importer::class, $name))->invoke(null, ...$args);

$assert(!class_exists('TinyCat\\Extension\\Loader'), 'The extension loader must be removed.');
$assert(!class_exists('TinyCat\\Extension\\Registry'), 'Extension registration must be removed.');
$assert(!class_exists('TinyCat\\Extension\\Store'), 'The extension store must be removed.');
$assert(!method_exists(\TinyCat\Users\Roles::class, 'register'), 'Roles must belong to core.');
$assert(!\TinyCat\Users\Roles::allowsLogin('bot'), 'Bot accounts cannot log in.');
$assert(!\TinyCat\Users\Roles::receivesNotifications('bot'), 'Bot accounts cannot receive notifications.');
$assert(!\TinyCat\Users\Roles::managedByCoreAdmin('bot'), 'Bot accounts use their dedicated administration.');
$assert(\TinyCat\Users\Roles::profileSchemaType('bot') === 'Organization', 'Bot profiles retain their schema type.');
$assert(array_diff(\TinyCat\Bots\Importer::requiredTables(), \TinyCat\Database\Schema::app_required_tables()) === [], 'Bot storage is required by core.');
$assert(array_keys(\TinyCat\Bots\Importer::scheduledTasks()) === ['feeds'], 'Feeds are always available to the scheduler.');
$assert(!is_file(\TinyCat\Support\Paths::publicPath('admin/extensions.php')), 'Extension administration must be removed.');
$assert(!method_exists(\TinyCat\Site\Sitemap::class, 'registerExtension'), 'Sitemaps cannot register extension sections.');

$assert(\TinyCat\Bots\Importer::feedSourceHash('https://EXAMPLE.com:443/feed/') === \TinyCat\Bots\Importer::feedSourceHash('https://example.com/feed'), 'Equivalent feed URLs must share a duplicate key.');
$assert(\TinyCat\Bots\Importer::feedSourceHash('file:///private/feed.xml') === '', 'Feed sources require HTTP or HTTPS.');
$tags = $invoke('feedCategoryTags', array_map(static fn (int $n): string => 'Category ' . $n, range(1, 12)));
$assert(substr_count($tags, '#category-') === 12, 'All feed category tags must survive.');
$assert(\TinyCat\Bots\FeedParser::parse( '<invalid') === [], 'Invalid XML must not produce posts.');

// Public literal IPs avoid DNS and external network requests in these parser tests.
$podcast = '<rss xmlns:p="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><p:image href="https://93.184.216.34/cover.jpg"/><item><guid>episode</guid><title>Episode</title><p:summary>Podcast summary</p:summary><enclosure type="audio/mpeg" url="https://93.184.216.34/audio.mp3"/></item><item><guid>own</guid><title>Own image</title><description>RSS description</description><p:summary>Other summary</p:summary><p:image href="https://93.184.216.34/episode.jpg"/></item></channel></rss>';
$podcastItems = \TinyCat\Bots\FeedParser::parse($podcast);
$assert($podcastItems[0]['description'] === 'Podcast summary' && $podcastItems[0]['image_urls'] === ['https://93.184.216.34/cover.jpg'], 'Podcast episodes use iTunes summaries and channel artwork without treating audio as an image.');
$assert($podcastItems[1]['description'] === 'RSS description' && $podcastItems[1]['image_urls'] === ['https://93.184.216.34/episode.jpg'], 'Episode artwork and RSS descriptions take precedence over podcast fallbacks.');
$sourceOnly = \TinyCat\Status\Links::status_link_prepare_metadata(
    ['normalized_url' => 'https://93.184.216.34/episode'], null, false,
    ['url_hash' => '', 'title' => 'Episode', 'description' => 'RSS description', 'image_urls' => []]
);
$assert($sourceOnly['description'] === 'RSS description' && $sourceOnly['title'] === 'Episode', 'Feed metadata survives even when the feed provides no artwork.');
$rss = '<rss version="2.0"><channel><item><guid>second</guid><title>Second</title><link>https://93.184.216.34/second</link><pubDate>Tue, 02 Jun 2026 10:00:00 GMT</pubDate></item><item><guid>first</guid><title>First</title><link>https://93.184.216.34/first</link><pubDate>Mon, 01 Jun 2026 10:00:00 GMT</pubDate><category>World News</category></item></channel></rss>';
$items = \TinyCat\Bots\FeedParser::parse( $rss);
$assert(array_column($items, 'guid') === ['first', 'second'], 'RSS posts must be ordered oldest first.');
$assert($items[0]['categories'] === ['World News'], 'RSS categories must survive parsing.');
$atom = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><id>atom-first</id><title>Atom title</title><link rel="alternate" href="https://93.184.216.34/atom"/><updated>2026-06-01T10:00:00Z</updated><author><name>Writer</name></author></entry></feed>';
$items = \TinyCat\Bots\FeedParser::parse( $atom);
$assert(count($items) === 1 && $items[0]['author'] === 'Writer', 'Atom posts must preserve their author.');
$assert($items[0]['url'] === 'https://93.184.216.34/atom', 'Atom alternate links must survive.');

$seznam = \TinyCat\Bots\FeedParser::parse((string) file_get_contents(__DIR__ . '/fixtures/seznam.xml'), 'https://93.184.216.34/feed');
$assert(count($seznam) === 1 && str_contains($seznam[0]['image_url'], 'photo.jpeg?fl=cro,0,0,1920,1080|res,1200,,3|jpg,80&size=large'), 'Publisher image URLs preserve crop parameters and decode XML entities.');
$assert($seznam[0]['author'] === 'Jana Nová, Petr Malý', 'Publisher authors use the namespace URI, independent of its prefix.');
$assert($seznam[0]['categories'] === ['Krimi', 'Stalo se'], 'Publisher sections become categories.');
$assert($seznam[0]['description'] === 'Krátký popis.' && $seznam[0]['image_urls'][1] === 'https://93.184.216.34/backup.jpg', 'Encoded content supplies fallback images without replacing the summary.');
$prefixed = \TinyCat\Bots\FeedParser::parse((string) file_get_contents(__DIR__ . '/fixtures/atom.xml'));
$assert($prefixed[0]['url'] === 'https://93.184.216.34/news/article?x=1&y=2', 'Prefixed Atom links respect nested xml:base and parent segments.');
$assert($prefixed[0]['author'] === 'Writer' && $prefixed[0]['categories'] === ['World'], 'Prefixed Atom author and category attributes survive.');
$assert($prefixed[0]['description'] === 'Full body', 'Atom XHTML content becomes plain text.');
$assert($prefixed[0]['image_urls'] === [
    'https://93.184.216.34/news/media/lead.jpg', 'https://93.184.216.34/news/media/small.jpg',
    'https://93.184.216.34/news/enclosed.jpg', 'https://93.184.216.34/news/body.jpg',
], 'Grouped media, Atom enclosures and XHTML images are ordered without video files.');
$unsafe = '<rss><channel><item><guid>x</guid><title>x</title><enclosure type="image/jpeg" url="http://127.0.0.1/secret"/><description><![CDATA[<img src="data:image/png,x"><img src="https://93.184.216.34/safe.jpg">]]></description></item></channel></rss>';
$assert(\TinyCat\Bots\FeedParser::parse($unsafe)[0]['image_urls'] === ['https://93.184.216.34/safe.jpg'], 'Unsafe image candidates do not hide later safe images.');
$assert(\TinyCat\Bots\FeedParser::parse('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>') === [], 'Feed parsing rejects document type declarations.');
$assert(\TinyCat\Bots\FeedParser::parse('<other/>') === [], 'Unrecognized XML documents have no feed items.');
$assert(count(\TinyCat\Bots\FeedParser::parse('<rss><channel>' . str_repeat('<item><guid>x</guid><title>x</title></item>', 105) . '</channel></rss>')) === 100, 'Feed work is bounded to one hundred entries.');

$videoDescription = \TinyCat\Bots\FeedParser::descriptionText( '<a href="https://youtu.be/abc123?si=share">Watch</a> https://example.com/remove', 2000);
$assert(str_contains($videoDescription, 'https://www.youtube.com/watch?v=abc123') && !str_contains($videoDescription, 'example.com'), 'Bot descriptions retain canonical video URLs and remove article links.');

foreach (['en', 'cs'] as $code) {
    $assert(\TinyCat\Support\Localization::t('bots.title', [], $code) !== 'bots.title', 'Bot translations must ship in core: ' . $code);
    $assert(\TinyCat\Support\Localization::t('cron.tasks.feeds', [], $code) !== 'cron.tasks.feeds', 'Feed scheduler translations must ship in core: ' . $code);
}

$assert(!str_contains(\TinyCat\Bots\Importer::defaultSourceTemplate(), '{{image}}'), 'Image uploads remain opt-in.');
$imageOnly = \TinyCat\Bots\FeedParser::parse('<rss><channel><item><enclosure type="image/png" url="https://93.184.216.34/photo.png"/></item></channel></rss>');
$assert(count($imageOnly) === 1 && $imageOnly[0]['guid'] === $imageOnly[0]['image_url'], 'Image-only RSS entries receive stable image identity without a title or article URL.');
$assert($invoke('renderPost', ['post_template' => "{{title}}\n{{image}}"], ['title' => 'Photo']) === 'Photo', 'Image placeholders do not appear in post text.');

require __DIR__ . '/formats.php';
echo "PASS built-in Bots ({$checks} checks)\n";
