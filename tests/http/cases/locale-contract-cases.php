<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';

// The shared fixture supplies HTTP helpers and a disposable database.
$localeAlternatives = null;
foreach (['cs', 'en', 'de', 'es', 'fr', 'pt', 'ru', 'it', 'nl', 'pl', 'tr', 'uk'] as $locale) {
    [$status, $body, $headers] = $exportRequest('/' . $locale . '/privacy', 'GET', ['Accept-Language' => 'en', 'X-Test-Role' => 'user']);
    $document = new DOMDocument();
    libxml_use_internal_errors(true); $document->loadHTML($body); libxml_clear_errors();
    $xpath = new DOMXPath($document);
    $assert($status === 200 && $xpath->evaluate('string(/html/@lang)') === $locale && ($headers['content-language'] ?? '') === $locale, 'URL language overrides browser and account: ' . $locale);
    $canonical = 'http://localhost/' . $locale . '/privacy';
    $assert($xpath->evaluate('string(//link[@rel="canonical"]/@href)') === $canonical, 'A translated policy has its own canonical: ' . $locale);
    $schema = json_decode($xpath->evaluate('string(//script[@type="application/ld+json"])'), true);
    $assert($schema['url'] === $canonical && $schema['inLanguage'] === $locale, 'Structured data uses the explicit URL language.');
    $alternatives = [];
    foreach ($xpath->query('//head/link[@hreflang]') as $link) { $alternatives[$link->getAttribute('hreflang')] = $link->getAttribute('href'); }
    $assert(count($alternatives) === 13 && $alternatives[$locale] === $canonical && $alternatives['x-default'] === 'http://localhost/privacy', 'Hreflang includes every locale, itself and x-default.');
    $localeAlternatives ??= $alternatives;
    $assert($alternatives === $localeAlternatives, 'Every translated policy exposes reciprocal identical alternatives.');
    $assert($xpath->query('//*[@data-language-menu]')->length === 0 && $xpath->query('//*[@data-language-suggestion]')->length === 0, 'Signed-in users manage language through their profile without browser-language prompts.');
}

[$status, $body, $headers] = $exportRequest('/en/status/10?compact=1', 'GET', ['Accept-Language' => 'cs-CZ,cs;q=0.9,en;q=0.8']);
$document = new DOMDocument();
libxml_use_internal_errors(true); $document->loadHTML($body); libxml_clear_errors();
$xpath = new DOMXPath($document);
$assert($status === 200 && !isset($headers['location']) && $xpath->query('//*[@data-language-menu]//a[@data-language-link]')->length === 12, 'Anonymous visitors have a crawlable navbar language picker without forced redirects.');
$assert(str_contains($xpath->evaluate('string(//*[@data-language-menu]/summary//*[local-name()="use"]/@href)'), '#flag-en'), 'The navbar flag follows the actual interface language.');
$assert($xpath->evaluate('string(//*[@data-language-menu]//a[@data-language-link="cs"]/@href)') === '/cs/status/10?compact=1', 'Language choices retain the current page and relevant query parameters.');
$assert($xpath->evaluate('string(//*[@data-language-suggestion]/@lang)') === 'cs' && str_contains($xpath->evaluate('string(//*[@data-language-suggestion])'), 'češtině'), 'A Czech browser gets a Czech-language offer on an English page.');
$assert($xpath->evaluate('string(//*[@data-language-suggestion]//a/@href)') === '/cs/status/10?compact=1', 'The language suggestion points to the corresponding localized page.');
$assert($xpath->query('//footer//*[@data-language-link]')->length === 0, 'The language picker is not duplicated in the footer.');
foreach ([['/cs/', 'cs-CZ,en;q=0.8'], ['/en/', 'en,cs;q=0.8'], ['/en/', 'ja']] as [$path, $preferences]) {
    [$status, $body] = $exportRequest($path, 'GET', ['Accept-Language' => $preferences]);
    $assert($status === 200 && !str_contains($body, 'data-language-suggestion='), 'Matching or unsupported preferred languages do not produce a suggestion.');
}
[$status, $body] = $exportRequest('/', 'GET', ['Accept-Language' => 'cs-CZ']);
$assert($status === 200 && str_contains($body, '#flag-cs'), 'An automatically detected interface language also selects the correct navbar flag.');

foreach (['/pl/', '/pl/status/10', '/pl/tag/test', '/pl/author/2', '/pl/search/all?q=test', '/pl/login', '/pl/register', '/pl/recovery'] as $path) {
    [$status, $body, $headers] = $exportRequest($path, 'GET', ['Accept-Language' => 'de']);
    $assert($status === 200 && str_contains($body, '<html lang="pl"') && ($headers['content-language'] ?? '') === 'pl', 'Public route renders in its explicit language: ' . $path);
    $assert(str_contains($body, 'data-interface-prefix="pl"') && str_contains($body, 'href="/pl/"'), 'The page shell retains its language prefix.');
}
[$status, $body] = $exportRequest('/pl/status/10');
$document = new DOMDocument();
libxml_use_internal_errors(true); $document->loadHTML($body); libxml_clear_errors();
$xpath = new DOMXPath($document);
$assert($xpath->evaluate('string(//link[@rel="canonical"]/@href)') === 'http://localhost/status/10' && $xpath->query('//head/link[@hreflang]')->length === 0, 'Untranslated posts consolidate UI variants under the original canonical.');
$assert(str_contains($body, 'href="/pl/author/1"') && str_contains($body, 'href="/pl/status/10"'), 'SSR component URLs retain the language.');
$postSchema = json_decode($xpath->evaluate('string(//script[@type="application/ld+json"])'), true);
$assert($postSchema['url'] === 'http://localhost/status/10', 'Post structured data agrees with its original canonical.');
$assert($xpath->evaluate('string(//meta[@property="og:locale"]/@content)') === 'pl_PL', 'Open Graph uses a valid language-region locale.');
[$status, $body, $headers] = $exportRequest('/pl/privacy', 'HEAD');
$assert($status === 200 && $body === '' && ($headers['content-language'] ?? '') === 'pl', 'Localized HEAD has GET metadata and no response body.');
foreach (['/zz/privacy', '/pl/admin', '/pl/api/site/pages', '/pl/assets/no.js', '/pl/author/2/feed', '/pl/pl/privacy'] as $path) {
    [$status] = $exportRequest($path);
    $assert($status === 404, 'Invalid and non-HTML prefixes do not expose aliases: ' . $path);
}

[$status, $payload] = $request('GET', '/api/site/pages?path=' . rawurlencode('/pl/status/10'), null, 'user', ['Accept-Language' => 'de']);
$assert($status === 200 && $payload['data']['locale'] === 'pl' && $payload['data']['url'] === '/pl/status/10', 'Page API accepts the same prefixed public paths.');
$assert($payload['data']['document']['url'] === 'http://localhost/status/10' && $payload['data']['item']['author']['url'] === '/pl/author/1', 'AJAX resources preserve localized navigation and the original canonical.');
[$status, $payload] = $request('GET', '/api/site/pages?path=' . rawurlencode('/en/status/10'), null, 'user', ['X-TinyCat-Locale' => 'pl']);
$assert($status === 200 && $payload['data']['locale'] === 'en', 'An explicit page API path takes precedence over its interface header.');
[$status, $payload] = $request('GET', '/api/statuses/10', null, 'user', ['X-TinyCat-Locale' => 'pl']);
$assert($status === 200 && $payload['data']['item']['url'] === '/pl/status/10', 'Other UI API calls inherit the explicit interface header.');
[$status, $payload] = $request('GET', '/api/statuses/10', null, 'user', ['X-TinyCat-Locale' => 'zz']);
$assert($status === 200 && $payload['data']['item']['url'] === '/status/10', 'Unsupported interface headers are ignored.');
[$status, $body] = $exportRequest('/sitemap-pages-1.xml');
$assert($status === 200 && str_contains($body, 'http://localhost/pl/privacy') && !str_contains($body, '/pl/status/'), 'Sitemap includes genuine translated pages without multiplying untranslated posts.');

$redirectContext = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 10]]);
file_get_contents('http://' . $address . '/pl?feed=following', false, $redirectContext);
$redirectHeaders = http_get_last_response_headers() ?? [];
$assert(str_contains($redirectHeaders[0], '301') && in_array('Location: /pl/?feed=following', $redirectHeaders, true), 'A bare locale root normalizes once and preserves query parameters.');

$localeDatabase = \tinycat_test_database($databasePath);
$assert($localeDatabase->query('SELECT locale FROM users WHERE id = 2')->fetchColumn() === 'en', 'Visiting a localized URL does not rewrite profile preferences.');
$localeDatabase = null;
file_get_contents('http://' . $address . '/pl/tag/t%C3%A9st', false, $redirectContext);
$redirectHeaders = http_get_last_response_headers() ?? [];
$assert(str_contains($redirectHeaders[0], '301') && in_array('Location: /pl/tag/test', $redirectHeaders, true), 'Noncanonical UTF-8 tags redirect to the canonical slug while retaining the language.');

// Two already persisted semantic health events; no health run is needed to render them.
$healthDatabase = \tinycat_test_database($databasePath);
$healthDatabase->exec("INSERT INTO notifications (user_id, type, notification_key, created_at, updated_at) VALUES (2, 'site_health_ok', 'site_health:900001', '2026-12-01 12:00:00', '2026-12-01 12:00:00'), (2, 'site_health_attention', 'site_health:900002', '2026-12-01 12:00:01', '2026-12-01 12:00:01')");
$healthBefore = $healthDatabase->query("SELECT * FROM notifications WHERE notification_key IN ('site_health:900001', 'site_health:900002') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
try {
    foreach (['pl' => ['site_health_ok' => 'Kontrola stanu witryny zakończona: wszystko jest w porządku.', 'site_health_attention' => 'Kontrola stanu witryny zakończona: coś wymaga uwagi.'], 'de' => ['site_health_ok' => 'Die Zustandsprüfung der Website ist abgeschlossen: Alles ist in Ordnung.', 'site_health_attention' => 'Die Zustandsprüfung der Website ist abgeschlossen: Etwas erfordert Aufmerksamkeit.']] as $locale => $messages) {
        foreach (['/api/notifications/preview', '/api/notifications'] as $endpoint) {
            [$status, $payload] = $request('GET', $endpoint, null, 'user', ['X-TinyCat-Locale' => $locale, 'Accept-Language' => 'en']);
            $actual = [];
            foreach ($payload['data']['items'] as $item) { if (isset($messages[$item['type']])) { $actual[$item['type']] = $item['message']; } }
            ksort($actual); $expected = $messages; ksort($expected);
            $assert($status === 200 && $actual === $expected, 'Notification resources follow the interface header, not the English account/site locale: ' . $locale . ' ' . $endpoint);
        }
        [$status, $state] = $request('GET', '/api/notifications/state', null, 'user', ['X-TinyCat-Locale' => $locale]);
        $assert($status === 200 && array_keys($state['data']) === ['unread_count', 'latest_id'], 'Language selection preserves state-only polling.');
        foreach (['/' . $locale . '/', '/' . $locale . '/notifications'] as $path) {
            [$status, $body, $headers] = $exportRequest($path, 'GET', ['X-Test-Role' => 'user', 'Accept-Language' => 'en']);
            $assert($status === 200 && ($headers['content-language'] ?? '') === $locale, 'Notification HTML follows the prefixed UI locale: ' . $path);
            foreach ($messages as $message) { $assert(str_contains($body, $message), 'Both existing health outcomes render in the public menu and full notification page: ' . $path); }
            $assert(!str_contains($body, 'Site health inspection completed:'), 'The prefixed UI does not retain an English health sentence: ' . $path);
        }
    }
    $assert($healthDatabase->query("SELECT * FROM notifications WHERE notification_key IN ('site_health:900001', 'site_health:900002') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $healthBefore, 'Reading health notices in different languages never rewrites persisted events or their read state.');
    $assert($healthDatabase->query('SELECT locale FROM users WHERE id = 2')->fetchColumn() === 'en', 'Notification localization preserves the account language.');
} finally {
    $healthDatabase->exec("DELETE FROM notifications WHERE notification_key IN ('site_health:900001', 'site_health:900002')");
    $healthDatabase = null;
}
