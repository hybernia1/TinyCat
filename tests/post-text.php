<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/autoload.php';
require_once dirname(__DIR__) . '/tools/post-text-cleanup.php';

use TinyCat\Status\PostText;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException('Post text check failed: ' . $checks); }
};
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
    $assert(PostText::normalize($before) === $after);
    $assert(PostText::normalize($after) === $after);
}
$assert(PostText::isReadMore('READ MORE »'));
$assert(!PostText::isReadMore('Read more about my work'));
$description = TinyCat\Bots\FeedParser::descriptionText('<p>First</p><p>Second […] <a href="https://example.com/">Read <b>more</b> »</a></p>', 1200);
$assert($description === 'First Second …');
$assert(!PostTextCleanup::safe('Read more', ''));
$assert(!PostTextCleanup::safe('#foo bar', '#foobar'));
$assert(!PostTextCleanup::safe('https://example.com/', 'https://example.org/'));
$metadata = TinyCat\Status\Links::status_link_data(['normalized_url' => 'https://example.com/', 'description' => '  Preview  […] Read more']);
$assert($metadata['description'] === 'Preview …');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, author_id INTEGER, published_at TEXT, body TEXT)');
    $insert = $pdo->prepare('INSERT INTO content VALUES (?, ?, ?, ?)');
    $insert->execute([1, 1, '2026-11-01', 'Human  post […]']);
    $insert->execute([2, 2, '2026-12-01', 'Bot  post […]']);
    $plan = PostTextCleanup::plan($pdo);
    $assert(count($plan['changes']) === 2);
    $pdo->exec("UPDATE content SET body = 'edited' WHERE id = 2");
    try { PostTextCleanup::apply($pdo, $plan); throw new LogicException('Conflict accepted'); }
    catch (RuntimeException) { $assert($pdo->query('SELECT body FROM content WHERE id = 1')->fetchColumn() === 'Human  post […]'); }
    $pdo->exec("UPDATE content SET body = 'Bot  post […]' WHERE id = 2");
    $assert(PostTextCleanup::apply($pdo, $plan) === 2);
    $assert(PostTextCleanup::plan($pdo)['changes'] === []);
    $assert(PostTextCleanup::apply($pdo, $plan, true) === 2);
    $assert($pdo->query('SELECT body FROM content WHERE id = 1')->fetchColumn() === 'Human  post […]');
}
echo "PASS post text ({$checks} checks)\n";
