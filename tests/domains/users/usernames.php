<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

require_once __DIR__ . '/../../support/cache-isolation.php';

define('TINYCAT', true);
require_once __DIR__ . '/../../../App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
foreach (['Al', 'Alice', 'Žaneta.Novák', 'Jan-Novak', '2Pac', 'old_user_', '李明', 'مريم', str_repeat('Ž', 32)] as $name) {
    $assert(\TinyCat\Core\Identity\Usernames::valid($name), 'Valid relaxed username rejected: ' . $name);
}
foreach (['A', '123', '１２３', '_Alice', '.Alice', 'Alice.', 'Alice-', 'Alice Smith', 'Alice@example.com', '../Alice', 'Alice/Smith', '<Alice>', "Ali\nce", "Alice\u{200B}", 'Alice😺', str_repeat('a', 33), str_repeat('Ž', 33)] as $name) {
    $assert(!\TinyCat\Core\Identity\Usernames::valid($name), 'Invalid username accepted: ' . $name);
}
$assert(\TinyCat\Core\Identity\Usernames::normalize('  Žaneta.Novák  ') === 'Žaneta.Novák', 'Normalization must preserve display case and diacritics.');
$assert(\TinyCat\Core\Identity\Usernames::key('ŽANETA.NOVÁK') === \TinyCat\Core\Identity\Usernames::key('žaneta.novák'), 'Mention lookup keys must be case insensitive for Unicode.');

if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
    $database = \tinycat_test_database();
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, username VARCHAR(254) UNIQUE NOT NULL, status VARCHAR(24) NOT NULL)');
    $insert = $database->prepare("INSERT INTO users (id, username, status) VALUES (?, ?, 'active')");
    foreach ([1 => 'Žaneta.Novák', 2 => 'Jan-Novak', 3 => 'old_user_', 7 => '2Pac', 8 => str_repeat('a', 32)] as $id => $name) {
        $insert->execute([$id, $name]);
    }
    \TinyCat\Core\Settings\Store::reset(); \TinyCat\Support\Localization::reset(); \TinyCat\Core\Identity\Authentication::reset(); \TinyCat\Database\Connection::set($database);
    \TinyCat\Core\Settings\Schema::install($database);
    $assert(\TinyCat\Core\Identity\Usernames::taken('ŽANETA.NOVÁK'), 'A differently cased username must remain taken.');
    $assert(!\TinyCat\Core\Identity\Usernames::taken('ŽANETA.NOVÁK', 1), 'The current account must be excluded from duplicate checks.');
    $assert(
        \TinyCat\Social\Posts\Mentions::normalize_mentions_for_storage('Ahoj @ŽANETA.NOVÁK. @2Pac @Jan-Novak @old_user_ @7') === 'Ahoj @1. @7 @2 @3 @7',
        'Unicode, case, punctuation, digit prefixes and existing numeric mention IDs must resolve consistently.'
    );
    $assert(\TinyCat\Social\Posts\Mentions::mentions_for_editing('@1 @7 @2 @3') === '@Žaneta.Novák @2Pac @Jan-Novak @old_user_', 'Mention editing must preserve stored display names.');
    $longName = '@' . str_repeat('a', 33);
    $assert(\TinyCat\Social\Posts\Mentions::normalize_mentions_for_storage($longName) === $longName, 'An overlong token must not resolve a matching username prefix.');
    $longDottedName = '@' . str_repeat('a', 32) . '.next';
    $assert(\TinyCat\Social\Posts\Mentions::normalize_mentions_for_storage($longDottedName) === $longDottedName, 'An overlong dotted name must not resolve a shorter username prefix.');
    $assert(\TinyCat\Social\Posts\Mentions::normalize_mentions_for_storage('mail@Jan-Novak.test @@2Pac') === 'mail@Jan-Novak.test @@2Pac', 'Email-like text and doubled @ signs must remain text.');
    $assert(\TinyCat\Social\Posts\NotificationEvents::mentionedUserIds('@7Unknown @7.Unknown @7.') === [7], 'Unresolved digit-prefixed names must not notify a matching numeric-ID prefix.');
    $assert(\TinyCat\Social\Posts\Mentions::mentions_for_editing('@7.Unknown @7') === '@7.Unknown @2Pac', 'Mention editing must leave unresolved dotted names intact.');
    $assert(\TinyCat\Social\Posts\InlinePresentation::text('@7.Unknown') === [['type' => 'text', 'text' => '@7.Unknown']], 'Unresolved dotted names must not link a numeric-ID prefix.');
}

foreach ([
    \TinyCat\Core\Ui\Components::render('users/auth-form', \TinyCat\Core\Identity\Resource::authenticationForm('register', '')),
    \TinyCat\Core\Identity\Views::adminFormFields(['create' => true]),
] as $form) {
    $assert(str_contains($form, 'pattern="' . \TinyCat\Core\Ui\Html::e(\TinyCat\Core\Identity\Usernames::pattern()) . '"'), 'Rendered username forms must use the shared validation pattern.');
}
echo "PASS relaxed usernames ({$checks} checks)\n";
