<?php
declare(strict_types=1);

require_once __DIR__ . '/cache-isolation.php';

define('TINYCAT', true);
require_once dirname(__DIR__) . '/App/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
foreach (['Al', 'Alice', 'Žaneta.Novák', 'Jan-Novak', '2Pac', 'old_user_', '李明', 'مريم', str_repeat('Ž', 32)] as $name) {
    $assert(\TinyCat\Users\Usernames::username_valid($name), 'Valid relaxed username rejected: ' . $name);
}
foreach (['A', '123', '１２３', '_Alice', '.Alice', 'Alice.', 'Alice-', 'Alice Smith', 'Alice@example.com', '../Alice', 'Alice/Smith', '<Alice>', "Ali\nce", "Alice\u{200B}", 'Alice😺', str_repeat('a', 33), str_repeat('Ž', 33)] as $name) {
    $assert(!\TinyCat\Users\Usernames::username_valid($name), 'Invalid username accepted: ' . $name);
}
$assert(\TinyCat\Users\Usernames::username_normalize('  Žaneta.Novák  ') === 'Žaneta.Novák', 'Normalization must preserve display case and diacritics.');
$assert(\TinyCat\Users\Usernames::username_key('ŽANETA.NOVÁK') === \TinyCat\Users\Usernames::username_key('žaneta.novák'), 'Mention lookup keys must be case insensitive for Unicode.');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $database = new PDO('sqlite::memory:');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->sqliteCreateCollation('USERNAME_CASE', static fn (string $a, string $b): int => strcmp(\TinyCat\Users\Usernames::username_key($a), \TinyCat\Users\Usernames::username_key($b)));
    $database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT COLLATE USERNAME_CASE UNIQUE NOT NULL, status TEXT NOT NULL)');
    $insert = $database->prepare("INSERT INTO users (id, username, status) VALUES (?, ?, 'active')");
    foreach ([1 => 'Žaneta.Novák', 2 => 'Jan-Novak', 3 => 'old_user_', 7 => '2Pac', 8 => str_repeat('a', 32)] as $id => $name) {
        $insert->execute([$id, $name]);
    }
    \TinyCat\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Users\Authentication::reset(); \TinyCat\Database\Connection::set($database);
    $assert(\TinyCat\Users\Usernames::user_username_taken('ŽANETA.NOVÁK'), 'A differently cased username must remain taken.');
    $assert(!\TinyCat\Users\Usernames::user_username_taken('ŽANETA.NOVÁK', 1), 'The current account must be excluded from duplicate checks.');
    $assert(
        \TinyCat\Status\Mentions::normalize_mentions_for_storage('Ahoj @ŽANETA.NOVÁK. @2Pac @Jan-Novak @old_user_ @7') === 'Ahoj @1. @7 @2 @3 @7',
        'Unicode, case, punctuation, digit prefixes and existing numeric mention IDs must resolve consistently.'
    );
    $assert(\TinyCat\Status\Mentions::mentions_for_editing('@1 @7 @2 @3') === '@Žaneta.Novák @2Pac @Jan-Novak @old_user_', 'Mention editing must preserve stored display names.');
    $longName = '@' . str_repeat('a', 33);
    $assert(\TinyCat\Status\Mentions::normalize_mentions_for_storage($longName) === $longName, 'An overlong token must not resolve a matching username prefix.');
    $longDottedName = '@' . str_repeat('a', 32) . '.next';
    $assert(\TinyCat\Status\Mentions::normalize_mentions_for_storage($longDottedName) === $longDottedName, 'An overlong dotted name must not resolve a shorter username prefix.');
    $assert(\TinyCat\Status\Mentions::normalize_mentions_for_storage('mail@Jan-Novak.test @@2Pac') === 'mail@Jan-Novak.test @@2Pac', 'Email-like text and doubled @ signs must remain text.');
    $assert(\TinyCat\Notifications\Delivery::mentionedUserIds('@7Unknown @7.Unknown @7.') === [7], 'Unresolved digit-prefixed names must not notify a matching numeric-ID prefix.');
    $assert(\TinyCat\Status\Mentions::mentions_for_editing('@7.Unknown @7') === '@7.Unknown @2Pac', 'Mention editing must leave unresolved dotted names intact.');
    $assert(\TinyCat\Status\Segments::text('@7.Unknown') === [['type' => 'text', 'text' => '@7.Unknown']], 'Unresolved dotted names must not link a numeric-ID prefix.');
}

foreach ([
    \TinyCat\Users\Views::authForm(['mode' => 'register']),
    \TinyCat\Users\Views::adminFormFields(['create' => true]),
] as $form) {
    $assert(str_contains($form, 'pattern="' . \TinyCat\Support\Html::e(\TinyCat\Users\Usernames::username_pattern()) . '"'), 'Rendered username forms must use the shared validation pattern.');
}
echo "PASS relaxed usernames ({$checks} checks)\n";
