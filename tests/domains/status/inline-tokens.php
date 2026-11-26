<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

use TinyCat\Social\Posts\Mentions;
use TinyCat\Social\Posts\Tags;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$database = \tinycat_test_database();
TinyCat\Database\Connection::set($database);
(new ReflectionProperty(TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
$database->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254), status VARCHAR(24)); INSERT INTO users VALUES (7, 'jana', 'active')");
foreach (['1', '2026', '0001', '١٢٣', '１２３'] as $number) {
    $assert(Tags::normalizeTag($number) === '', 'Pure numeric tags are invalid: ' . $number);
    $assert(Tags::extractTags('Feature Update #' . $number) === [], 'Numeric prose does not become a tag');
    $assert(Tags::normalizeTagText('Update #' . $number) === 'Update #' . $number, 'Numeric prose remains unchanged');
}
foreach (['1980s', '3d', 'gothic-1', 'gothic-remake'] as $tag) {
    $assert(Tags::normalizeTag($tag) === $tag, 'Alphanumeric tags remain supported');
    $assert(Tags::extractTags('#' . $tag) === [$tag], 'Alphanumeric tags are extracted');
}
$assert(Tags::normalizeTag(str_repeat('a', Tags::MAX_BYTES)) === str_repeat('a', Tags::MAX_BYTES), 'The exact storage byte limit is accepted');
$assert(Tags::normalizeTag(str_repeat('a', Tags::MAX_BYTES + 1)) === '', 'A tag beyond the storage byte limit is rejected');
$assert(Tags::extractTags('#Cafe #cafe #other #CAFE') === ['cafe', 'other'], 'Normalized tags are deduplicated in first occurrence order');
$assert(Tags::normalizeTagText('#' . str_repeat('a', Tags::MAX_BYTES + 1)) === '#' . str_repeat('a', Tags::MAX_BYTES + 1), 'Rejected overlong tags remain literal prose');
$assert(TinyCat\Core\Text\Text::slug('Mixed words', '_') === 'mixed_words', 'The canonical slug implementation preserves custom separators');
$sample = 'Feature Update #1 https://indian-tv.cz/clanek/gothic-remake #novinky #gothic-remake';
$assert(Tags::extractTags($sample) === ['novinky', 'gothic-remake'], 'RSS update number is prose while trailing named tags are retained');
$assert(Tags::normalizeTagText($sample) === $sample, 'RSS body is preserved');
$imported = TinyCat\Social\Bots\FeedPublication::renderPost([], ['title' => 'Feature Update #1', 'description' => 'Gothic Remake', 'url' => 'https://example.test/article', 'categories' => ['2026', '1980s', 'novinky']]);
$assert(str_contains($imported, 'Feature Update #1'), 'RSS article numbering remains visible');
$assert(!str_contains($imported, '#2026') && Tags::extractTags($imported) === ['1980s', 'novinky'], 'RSS categories omit numeric tags and retain mixed names');
$assert(Tags::normalizeTagText("#Cafe\u{0301}") === '#cafe', 'Combining diacritics belong to the complete tag token');
$assert(Tags::extractTags("#Cafe\u{0301}") === ['cafe'], 'Extraction uses the same Unicode token boundaries as normalization');
$assert(TinyCat\Social\Posts\PostCreation::tagRejection('#' . str_repeat('1', 100)) === null, 'A long numeric literal is not rejected as an overlong tag');
$assert(TinyCat\Social\Posts\PostCreation::tagRejection('https://example.test/#' . str_repeat('a', 100)) === null, 'URL fragments are not validated as tags');
foreach (['https://example.test/@jana#Žluťoučký', 'www.example.test/@7#novinky', 'example.test/path?user=@jana#štítek'] as $url) {
    $assert(Mentions::normalize_mentions_for_storage($url . ' @jana') === $url . ' @7', 'Only prose mentions are normalized');
    $assert(Mentions::mentions_for_editing($url . ' @7') === $url . ' @jana', 'Editing preserves literal URLs');
    $assert(Tags::normalizeTagText($url . ' #Žluťoučký') === $url . ' #zlutoucky', 'Only prose tags are normalized');
    $assert(Tags::extractTags($url) === [], 'URL fragments do not become tags');
    $assert(TinyCat\Social\Posts\NotificationEvents::mentionedUserIds($url) === [], 'Numeric URL paths do not notify users');
}
$assert(Mentions::normalize_mentions_for_storage('Ahoj,@jana') === 'Ahoj,@7', 'Punctuation allows mentions');
$assert(Tags::extractTags('mail@#tag ##tag a.#tag') === [], 'Invalid token boundaries are ignored');
$segments = TinyCat\Social\Posts\InlinePresentation::text('Update #1 #1980s');
$assert(count(array_filter($segments, static fn (array $segment): bool => $segment['type'] === 'tag')) === 1, 'Rendering links only the alphanumeric tag');
$wall = TinyCat\Social\Posts\InlinePresentation::text('Article #one #two #three #four #five #six');
$compact = TinyCat\Social\Posts\InlinePresentation::compactTags($wall);
$overflow = $compact[array_key_last($compact)];
$assert($overflow['type'] === 'tag-overflow' && $overflow['count'] === 3, 'Long tag runs show three tags and an exact overflow count');
$restored = array_slice($compact, 0, -1);
array_push($restored, ...$overflow['segments']);
$assert($restored === $wall, 'Expanding preserves every tag, URL and separator in order');
$sentence = TinyCat\Social\Posts\InlinePresentation::text('#one is #two and #three with #four and #five');
$assert(TinyCat\Social\Posts\InlinePresentation::compactTags($sentence) === $sentence, 'Tags inside prose are never hidden');
$short = TinyCat\Social\Posts\InlinePresentation::text('#one #two #three #four');
$assert(TinyCat\Social\Posts\InlinePresentation::compactTags($short) === $short, 'Short tag runs stay visible');
$html = TinyCat\Core\Ui\Components::render('publishing/segments', ['segments' => $compact]);
$assert(str_contains($html, '<details class="status-tags-overflow">') && str_contains($html, '# +3') && str_contains($html, '/tag/six'), 'Shared rendering exposes all overflow links through native disclosure');
$parts = TinyCat\Social\Posts\InlinePresentation::splitTags($compact);
$assert(count($parts['text_segments']) === 1 && count($parts['tag_segments']) > 0, 'Trailing tags are separated from the translatable text');
$bodyHtml = TinyCat\Core\Ui\Components::render('status/body', ['item' => $parts]);
$assert(str_contains($bodyHtml, 'class="status-tags" translate="no"') && str_contains($bodyHtml, '<summary class="badge">'), 'Tags opt out of translation and the disclosure uses a badge');
$assert(TinyCat\Social\Posts\InlinePresentation::splitTags(TinyCat\Social\Posts\InlinePresentation::text('#one sentence'))['tag_segments'] === [], 'Inline tags remain inside prose');
$database->exec('CREATE TABLE terms (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT); CREATE TABLE content_tags (content_id INT UNSIGNED, term_id INT UNSIGNED)');
foreach ([1 => '1', 2 => '1980s', 3 => '2026', 4 => '3d', 5 => 'gothic-1'] as $id => $name) {
    $database->prepare('INSERT INTO terms VALUES (?, ?)')->execute([$id, $name]);
    $database->prepare('INSERT INTO content_tags VALUES (10, ?)')->execute([$id]);
}
$migration = require __DIR__ . '/../../../migrations/20261101_002_numeric_tags.php';
$migration($database);
$migration($database);
$assert($database->query('SELECT name FROM terms ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['1980s', '3d', 'gothic-1'], 'Migration preserves mixed tags and is repeatable');
$assert(array_map('intval', $database->query('SELECT term_id FROM content_tags ORDER BY term_id')->fetchAll(PDO::FETCH_COLUMN)) === [2, 4, 5], 'Migration removes only numeric tag relationships');
echo "PASS inline mentions, numeric tags, protected URLs and migration ({$checks} checks)\n";
