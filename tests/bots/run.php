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
$assert($invoke('parseFeed', '<invalid') === [], 'Invalid XML must not produce posts.');

// Public literal IPs avoid DNS and external network requests in these parser tests.
$rss = '<rss version="2.0"><channel><item><guid>second</guid><title>Second</title><link>https://93.184.216.34/second</link><pubDate>Tue, 02 Jun 2026 10:00:00 GMT</pubDate></item><item><guid>first</guid><title>First</title><link>https://93.184.216.34/first</link><pubDate>Mon, 01 Jun 2026 10:00:00 GMT</pubDate><category>World News</category></item></channel></rss>';
$items = $invoke('parseFeed', $rss);
$assert(array_column($items, 'guid') === ['first', 'second'], 'RSS posts must be ordered oldest first.');
$assert($items[0]['categories'] === ['World News'], 'RSS categories must survive parsing.');
$atom = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><id>atom-first</id><title>Atom title</title><link rel="alternate" href="https://93.184.216.34/atom"/><updated>2026-06-01T10:00:00Z</updated><author><name>Writer</name></author></entry></feed>';
$items = $invoke('parseFeed', $atom);
$assert(count($items) === 1 && $items[0]['author'] === 'Writer', 'Atom posts must preserve their author.');
$assert($items[0]['url'] === 'https://93.184.216.34/atom', 'Atom alternate links must survive.');

$videoDescription = $invoke('feedDescriptionText', '<a href="https://youtu.be/abc123?si=share">Watch</a> https://example.com/remove', 2000);
$assert(str_contains($videoDescription, 'https://www.youtube.com/watch?v=abc123') && !str_contains($videoDescription, 'example.com'), 'Bot descriptions retain canonical video URLs and remove article links.');

foreach (['en', 'cs'] as $code) {
    $assert(\TinyCat\Support\Localization::t('bots.title', [], $code) !== 'bots.title', 'Bot translations must ship in core: ' . $code);
    $assert(\TinyCat\Support\Localization::t('cron.tasks.feeds', [], $code) !== 'cron.tasks.feeds', 'Feed scheduler translations must ship in core: ' . $code);
}

echo "PASS built-in Bots ({$checks} checks)\n";
