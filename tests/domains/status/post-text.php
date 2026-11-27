<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php';
require_once __DIR__ . '/../../../tools/maintenance/post-text-cleanup.php';

use TinyCat\Social\Bots\FeedPublication;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException('Post text check failed: ' . $checks); }
};
// HTML sources and manually entered prose have distinct contracts.
foreach ([
    '<3 glad to hear that' => '<3 glad to hear that',
    '&lt;b&gt;hello&lt;/b&gt;' => 'hello',
    '<p>one</p><p>two</p>' => 'one two',
    "<div><p>\u{017D}lu\u{0165}ou\u{010D}k\u{00FD}&nbsp;\u{732B}</p><br>\u{1F469}\u{200D}\u{1F4BB}</div>" => "\u{017D}lu\u{0165}ou\u{010D}k\u{00FD} \u{732B} \u{1F469}\u{200D}\u{1F4BB}",
    '<b>outer <i>inner</i></b>' => 'outer inner',
    '&amp;lt;b&amp;gt;literal&amp;lt;/b&amp;gt;' => '&lt;b&gt;literal&lt;/b&gt;',
] as $html => $plain) {
    $assert(\TinyCat\Core\Text\Text::htmlText($html) === $plain);
    $assert(\TinyCat\Core\Feeds\FeedParser::parse('<rss><channel><item><guid>text</guid><title><![CDATA[' . $html . ']]></title></item></channel></rss>')[0]['title'] === $plain);
}
$assert(\TinyCat\Core\Text\Text::plain_text_limit('&lt;b&gt;hello&lt;/b&gt; <3', 100) === '&lt;b&gt;hello&lt;/b&gt; <3');
$assert(\TinyCat\Core\Text\Text::htmlText("<p>\u{732B}\u{017E}lu\u{0165}</p>", 2) === "\u{732B}\u{017E}");
$assert(\TinyCat\Core\Text\Text::htmlText('text', 0) === '');
$assert(\TinyCat\Core\Text\Text::excerpt('<p>one</p><p>two</p>') === 'one two');
$url = "https://example.com/a\u{200B}b?x=[...]&q=%C5%BE#fragment:-)";
$assert(\TinyCat\Social\Posts\PostCreation::normalize('Text [...] ' . $url . ' :)') === "Text \u{2026} " . $url . ' :)');
$assert(\TinyCat\Social\Posts\InlinePresentation::render_status_emoticons('Hi :) ' . $url) === "Hi \u{1F642} " . $url);
$cases = [
    "  Title\r\n \r\n\r\nText\t  with\u{00A0} spaces  \n\n\nhttps://example.com/  #fiction  #text " => "Title\n\nText with spaces\n\nhttps://example.com/ #fiction #text",
    "Story This […]\n\nRead more → https://example.com/?x=[...] #fiction" => "Story This …\n\nhttps://example.com/?x=[...] #fiction",
    'An excerpt. Continue reading…' => 'An excerpt.',
    "Story ... Read more The post Article appeared first on Publisher.\n\nhttps://example.com/" => "Story ...\n\nhttps://example.com/",
    'Example. The post Title appeared first on Site. #fiction' => 'Example. #fiction',
    'Read more www.example.com/?x=[...] #text' => 'www.example.com/?x=[...] #text',
    'example.com/?x=[...]' => 'example.com/?x=[...]',
    'The post X appeared first on https://example.com/' => 'The post X appeared first on https://example.com/',
    "Text […]\nČíst dál\n\nhttps://example.com/" => "Text …\n\nhttps://example.com/",
    'I want to read more about fiction.' => 'I want to read more about fiction.',
    'Continue reading the documentation.' => 'Continue reading the documentation.',
    'I love you <3! 2 < 3. 👩‍💻 #text @12' => 'I love you <3! 2 < 3. 👩‍💻 #text @12',
    "Text\u{200B}\u{FEFF} text" => 'Text text',
    "Text \u{200B} text https://example.com/a\u{200B}b?x=[...]" => "Text text https://example.com/a\u{200B}b?x=[...]",
];
foreach ($cases as $before => $after) {
    $assert(FeedPublication::cleanImportBoilerplate($before) === $after);
    $assert(FeedPublication::cleanImportBoilerplate($after) === $after);
}
$assert(TinyCat\Social\Posts\PostCreation::normalize('An excerpt. Continue reading') === 'An excerpt. Continue reading');
$assert(TinyCat\Social\Posts\PostCreation::normalize('The post Story appeared first on Site.') === 'The post Story appeared first on Site.');
TinyCat\Social\Module::initialize();
$assert(TinyCat\Social\Posts\PostCreation::fromText('&lt;b&gt;literal&lt;/b&gt; <3')->body === '&lt;b&gt;literal&lt;/b&gt; <3');
$assert(TinyCat\Social\Posts\PostCreation::fromText(FeedPublication::cleanImportBoilerplate(str_repeat("The post X appeared first on Site.\n", 100) . 'Preserved tail'))->body === 'Preserved tail');
$assert(TinyCat\Social\Posts\PostCreation::fromText('An excerpt. Continue reading')->body === 'An excerpt. Continue reading');
$assert(FeedPublication::isReadMore('READ MORE »'));
$assert(!FeedPublication::isReadMore('Read more about my work'));
$description = TinyCat\Social\Bots\FeedPublication::descriptionText('<p>First</p><p>Second […] <a href="https://example.com/">Read <b>more</b> »</a></p>', 1200);
$assert($description === 'First Second …');
$assert(!PostTextCleanup::safe('Read more', ''));
$assert(!PostTextCleanup::safe('#foo bar', '#foobar'));
$assert(!PostTextCleanup::safe('https://example.com/', 'https://example.org/'));
$metadata = TinyCat\Social\Posts\Links::status_link_data(['normalized_url' => 'https://example.com/', 'description' => '  Preview  […] Read more']);
$assert($metadata['description'] === 'Preview …');

if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
    $pdo = \tinycat_test_database();
    $pdo->exec('CREATE TABLE social_posts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, author_id INT UNSIGNED, published_at DATETIME, body TEXT, INDEX content_feed_index (published_at,id), INDEX content_sidebar_index (published_at,author_id,id), INDEX content_author_index (author_id,published_at,id))');
    $insert = $pdo->prepare('INSERT INTO social_posts (id, author_id, published_at, body) VALUES (?, ?, ?, ?)');
    $insert->execute([1, 1, '2026-11-01', 'Human  post […]']);
    $insert->execute([2, 2, '2026-12-01', 'Bot  post […]']);
    $plan = PostTextCleanup::plan($pdo);
    $assert(count($plan['changes']) === 2);
    $pdo->exec("UPDATE social_posts SET body = 'edited' WHERE id = 2");
    try { PostTextCleanup::apply($pdo, $plan); throw new LogicException('Conflict accepted'); }
    catch (RuntimeException) { $assert($pdo->query('SELECT body FROM social_posts WHERE id = 1')->fetchColumn() === 'Human  post […]'); }
    $pdo->exec("UPDATE social_posts SET body = 'Bot  post […]' WHERE id = 2");
    $assert(PostTextCleanup::apply($pdo, $plan) === 2);
    $assert(PostTextCleanup::plan($pdo)['changes'] === []);
    $assert(PostTextCleanup::apply($pdo, $plan, true) === 2);
    $assert($pdo->query('SELECT body FROM social_posts WHERE id = 1')->fetchColumn() === 'Human  post […]');
}
echo "PASS post text ({$checks} checks)\n";
