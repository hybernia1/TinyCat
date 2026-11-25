<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require_once __DIR__ . '/../../App/Core/Runtime/autoload.php';

use TinyCat\Core\Installation\Pages;
use TinyCat\Support\Components;

\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration(['install' => ['complete' => false, 'locale' => 'en']]));
\TinyCat\Support\Localization::locale('en');
// Presentation must work before a usable application database exists.
\TinyCat\Database\Connection::set(\tinycat_test_database());
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$document = \TinyCat\Core\Ui\Document::frame([
    'title' => '<Setup>', 'htmlAttributes' => ['lang' => 'en'], 'bodyAttributes' => ['data-fixture' => '"><unsafe>'],
    'styles' => ['/fixture.css'], 'scripts' => ['/fixture.js'], 'csrfToken' => 'fixture-token',
    'flashToasts' => [['message' => '</template><script>unsafe</script>', 'type' => 'danger']],
    'skipLink' => false, 'colorScheme' => false,
], new \TinyCat\Support\Markup('<main>Setup</main>'));
$assert(substr_count($document, '<!doctype html>') === 1 && substr_count($document, '<html') === 1 && substr_count($document, '<head>') === 1 && substr_count($document, '<body') === 1, 'The shared frame owns one complete document before database setup.');
$assert(str_contains($document, '<title>&lt;Setup&gt;</title>') && str_contains($document, '&quot;&gt;&lt;unsafe&gt;'), 'Document text and attributes are escaped.');
$assert(!str_contains($document, '</template><script>unsafe') && str_contains($document, 'data-tinycat-flashes'), 'Flash serialization cannot escape its template.');
$flashItems = [['message' => 'Žluťoučký </template> & "quoted" / path', 'type' => 'info'], ['message' => 'Second message', 'type' => 'success']];
$flashDocument = \TinyCat\Core\Ui\Document::frame(['flashToasts' => $flashItems, 'skipLink' => false], new \TinyCat\Support\Markup(''));
preg_match('~<template data-tinycat-flashes>(.*?)</template>~s', $flashDocument, $flashMatch);
$assert(substr_count($flashDocument, 'data-tinycat-flashes') === 1 && json_decode($flashMatch[1] ?? '', true, 512, JSON_THROW_ON_ERROR) === $flashItems, 'Flash messages preserve Unicode, unsafe text, types and order in one JSON template.');
$emptyDocument = \TinyCat\Core\Ui\Document::frame(['flashToasts' => [], 'skipLink' => false], new \TinyCat\Support\Markup(''));
$assert(!str_contains($emptyDocument, 'data-tinycat-flashes'), 'An empty flash list does not emit a template.');
$assert(substr_count($document, 'src="/fixture.js"') === 1 && substr_count($document, 'href="/fixture.css"') === 1, 'Assets are emitted once by the frame.');
$assert(!str_contains($document, 'skip-link') && !str_contains($document, 'color-scheme') && !str_contains($document, 'googletagmanager'), 'Installation can omit public document policies.');
try {
    \TinyCat\Core\Ui\Document::frame(['bodyAttributes' => ['onload' => 'unsafe']], new \TinyCat\Support\Markup(''));
    throw new RuntimeException('Executable document attributes must be rejected.');
} catch (InvalidArgumentException) {
    // Only explicit language, class and data attributes are accepted.
}
$render = static fn (array $data): string => Components::render('installation/page', $data);
$languages = ['en' => ['code' => 'en', 'label' => 'English'], 'cs' => ['code' => 'cs', 'label' => '<Czech & language>']];
$language = Pages::prepare('language', $languages, ['locale' => 'cs']);
$html = $render($language);
$assert(str_contains($html, 'value="cs" selected') && !str_contains($html, 'value="en" selected'), 'Exactly the selected language is selected.');
$assert(str_contains($html, '&lt;Czech &amp; language&gt;'), 'Language labels are escaped.');
$assert(substr_count($html, 'aria-current="step"') === 1 && substr_count($html, '<form ') === 1, 'Only one current step and one active form are rendered.');
$assert(str_contains($html, 'action="/api/installation/steps/language"') && str_contains($html, 'name="_csrf"') && str_contains($html, 'data-installation-form'), 'Language submission preserves its endpoint and form lifecycle.');
$assert(Pages::prepare('unknown', $languages, [])['current'] === 'language', 'Unknown steps fall back to language.');
$database = Pages::prepare('db', [], ['database' => ['host' => '"><script>bad</script>', 'password' => 'DO-NOT-RENDER']]);
$assert(!array_key_exists('password', $database['database']), 'The database password is absent from presentation inputs.');
$html = $render($database);
$assert(!str_contains($html, '<script>bad') && str_contains($html, '&lt;script&gt;bad&lt;/script&gt;') && !str_contains($html, 'DO-NOT-RENDER'), 'Database values are escaped and passwords stay blank.');
$assert(str_contains($html, 'name="port"') && str_contains($html, 'max="65535"') && str_contains($html, 'value="utf8mb4"'), 'Database constraints and defaults are preserved.');
$admin = Pages::prepare('admin', [], []);
$html = $render($admin);
$assert(str_contains($html, 'name="password_confirm"') && substr_count($html, 'autocomplete="new-password"') === 2, 'Both administrator password fields retain their constraints.');
$assert(str_contains($html, 'pattern="') && str_contains($html, 'name="_install_step" value="admin"'), 'Username constraints and step identity are present.');
$tables = Pages::prepare('tables', [], []);
$tables['tables'] = [['name' => '<table>', 'purpose' => 'Test table', 'exists' => true], ['name' => 'missing', 'purpose' => 'Missing table', 'exists' => false]];
$tables['table_error'] = '<connection failed>';
$html = $render($tables);
$assert(str_contains($html, '&lt;connection failed&gt;') && str_contains($html, '&lt;table&gt;') && str_contains($html, 'Test table'), 'Table errors and names are escaped.');
$assert(str_contains($html, 'badge badge-primary') && str_contains($html, 'action="/api/installation/steps/tables"'), 'Table state and create action are available.');
$done = Pages::prepare('done', [], []);
$html = $render($done);
$assert(!str_contains($html, '<form ') && str_contains($html, 'href="/admin"'), 'The completion step has navigation and no mutating form.');
foreach (['language' => $language, 'db' => $database, 'admin' => $admin, 'tables' => $tables] as $step => $data) {
    $html = $render($data + ['error' => '<failed>', 'success' => '<saved>']);
    $assert(str_contains($html, '&lt;failed&gt;') && str_contains($html, '&lt;saved&gt;'), 'Flash messages are escaped for ' . $step);
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $match);
    $assert(isset($match[1]) && hash_equals(\TinyCat\Core\Security\Csrf::token(), html_entity_decode($match[1])), 'Every form receives the current CSRF token.');
}
echo "PASS installation component presentation before database setup\n";
