<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__) . '/App/bootstrap.php';

use TinyCat\Status\Mentions;
use TinyCat\Status\Tags;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
TinyCat\Database\Connection::set($database);
(new ReflectionProperty(TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
$database->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, status TEXT); INSERT INTO users VALUES (7, 'jana', 'active')");
foreach (['1', '2026', '0001', '١٢٣', '１２３'] as $number) {
    $assert(Tags::status_tag_normalize($number) === '', 'Pure numeric tags are invalid: ' . $number);
    $assert(Tags::status_tags_from_text('Feature Update #' . $number) === [], 'Numeric prose does not become a tag');
    $assert(Tags::normalize_tags_for_storage('Update #' . $number) === 'Update #' . $number, 'Numeric prose remains unchanged');
}
foreach (['1980s', '3d', 'gothic-1', 'gothic-remake'] as $tag) {
    $assert(Tags::status_tag_normalize($tag) === $tag, 'Alphanumeric tags remain supported');
    $assert(Tags::status_tags_from_text('#' . $tag) === [$tag], 'Alphanumeric tags are extracted');
}
$sample = 'Feature Update #1 https://indian-tv.cz/clanek/gothic-remake #novinky #gothic-remake';
$assert(Tags::status_tags_from_text($sample) === ['novinky', 'gothic-remake'], 'RSS update number is prose while trailing named tags are retained');
$assert(Tags::normalize_tags_for_storage($sample) === $sample, 'RSS body is preserved');
$imported = TinyCat\Bots\Importer::renderPost([], ['title' => 'Feature Update #1', 'description' => 'Gothic Remake', 'url' => 'https://example.test/article', 'categories' => ['2026', '1980s', 'novinky']]);
$assert(str_contains($imported, 'Feature Update #1'), 'RSS article numbering remains visible');
$assert(!str_contains($imported, '#2026') && Tags::status_tags_from_text($imported) === ['1980s', 'novinky'], 'RSS categories omit numeric tags and retain mixed names');
$assert(Tags::normalize_tags_for_storage("#Cafe\u{0301}") === '#cafe', 'Combining diacritics belong to the complete tag token');
$assert(Tags::status_tags_from_text("#Cafe\u{0301}") === ['cafe'], 'Extraction uses the same Unicode token boundaries as normalization');
$assert(TinyCat\Status\PostCreation::tagRejection('#' . str_repeat('1', 100)) === null, 'A long numeric literal is not rejected as an overlong tag');
$assert(TinyCat\Status\PostCreation::tagRejection('https://example.test/#' . str_repeat('a', 100)) === null, 'URL fragments are not validated as tags');
foreach (['https://example.test/@jana#Žluťoučký', 'www.example.test/@7#novinky', 'example.test/path?user=@jana#štítek'] as $url) {
    $assert(Mentions::normalize_mentions_for_storage($url . ' @jana') === $url . ' @7', 'Only prose mentions are normalized');
    $assert(Mentions::mentions_for_editing($url . ' @7') === $url . ' @jana', 'Editing preserves literal URLs');
    $assert(Tags::normalize_tags_for_storage($url . ' #Žluťoučký') === $url . ' #zlutoucky', 'Only prose tags are normalized');
    $assert(Tags::status_tags_from_text($url) === [], 'URL fragments do not become tags');
    $assert(TinyCat\Notifications\Delivery::mentionedUserIds($url) === [], 'Numeric URL paths do not notify users');
}
$assert(Mentions::normalize_mentions_for_storage('Ahoj,@jana') === 'Ahoj,@7', 'Punctuation allows mentions');
$assert(Tags::status_tags_from_text('mail@#tag ##tag a.#tag') === [], 'Invalid token boundaries are ignored');
$segments = TinyCat\Status\Segments::text('Update #1 #1980s');
$assert(count(array_filter($segments, static fn (array $segment): bool => $segment['type'] === 'tag')) === 1, 'Rendering links only the alphanumeric tag');
$database->exec('CREATE TABLE terms (id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE content_tags (content_id INTEGER, term_id INTEGER)');
foreach ([1 => '1', 2 => '1980s', 3 => '2026', 4 => '3d', 5 => 'gothic-1'] as $id => $name) {
    $database->prepare('INSERT INTO terms VALUES (?, ?)')->execute([$id, $name]);
    $database->prepare('INSERT INTO content_tags VALUES (10, ?)')->execute([$id]);
}
$migration = require dirname(__DIR__) . '/migrations/20261101_002_numeric_tags.php';
$migration($database);
$migration($database);
$assert($database->query('SELECT name FROM terms ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['1980s', '3d', 'gothic-1'], 'Migration preserves mixed tags and is repeatable');
$assert(array_map('intval', $database->query('SELECT term_id FROM content_tags ORDER BY term_id')->fetchAll(PDO::FETCH_COLUMN)) === [2, 4, 5], 'Migration removes only numeric tag relationships');
echo "PASS inline mentions, numeric tags, protected URLs and migration ({$checks} checks)\n";
