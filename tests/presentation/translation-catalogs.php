<?php
declare(strict_types=1);

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/autoload.php';
$validate = require dirname(__DIR__, 2) . '/tools/translation-catalogs.php';
$checks = 0;
$directory = sys_get_temp_dir() . '/tinycat-translations-' . bin2hex(random_bytes(8));
$app = '{"common":{"greeting":"Hello {name}: {count}","empty":""},"other":{"empty":""}}';
$emails = '{"signature":"{site}","templates":{"welcome":{"subject":"{site}","body":"Hello {username}"}}}';
$reset = static function () use ($directory, $app, $emails): void {
    foreach (['en', 'cs'] as $locale) {
        file_put_contents($directory . '/App/Support/lang/' . $locale . '.json', $app);
        file_put_contents($directory . '/App/Mail/lang/' . $locale . '.json', $emails);
    }
};
$reject = static function (string $file, string $source, string $expected) use ($validate, $directory, $reset, &$checks): void {
    $reset();
    file_put_contents($directory . '/' . $file, $source);
    try {
        $validate($directory);
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), $file . ':') || !str_contains($error->getMessage(), $expected)) {
            throw new RuntimeException('Unexpected diagnostic: ' . $error->getMessage(), 0, $error);
        }
        $checks++;
        return;
    }
    throw new RuntimeException('Invalid catalog accepted: ' . $expected);
};
mkdir($directory);
mkdir($directory . '/lang');
mkdir($directory . '/App');
foreach (['Support', 'Mail', 'Example'] as $owner) {
    mkdir($directory . '/App/' . $owner);
    mkdir($directory . '/App/' . $owner . '/lang');
}
foreach (['en', 'cs'] as $locale) {
    file_put_contents($directory . '/lang/' . $locale . '.json', '{"name":"Test"}');
}
try {
    $reset();
    $catalogs = $validate($directory);
    if ($catalogs['cs']['app']['common.empty'] !== '') { throw new RuntimeException('Empty translations must remain valid'); }
    $checks++;
    $reject('App/Support/lang/cs.json', '{"common":{"empty":"a","empty":"b"}}', 'duplicate key common.empty');
    $reject('App/Support/lang/cs.json', '{"common":{"empty":"a","\u0065mpty":"b"}}', 'duplicate key common.empty');
    $reject('App/Support/lang/cs.json', '{"common":{"empty":[]}}', 'expected string at common.empty');
    $reject('App/Support/lang/cs.json', '{"common":{"empty":false}}', 'expected string at common.empty');
    $reject('App/Support/lang/cs.json', '{"common":{}}', 'empty namespace common');
    $reject('App/Support/lang/cs.json', '{"common":{"one":"1","other":"many"}}', 'incomplete plural forms at common');
    $reject('App/Support/lang/cs.json', '{"common":{"one":"1","other":"many","single":"exactly one"}}', 'incomplete plural forms at common');
    $reject('App/Support/lang/cs.json', str_replace('Hello', 'prob?ha', $app), 'suspicious encoding marker at common.greeting');
    $reject('App/Support/lang/en.json', str_replace('Hello', 'bad??', $app), 'suspicious encoding marker at common.greeting');
    $reject('App/Support/lang/en.json', str_replace('Hello', '\ufffd', $app), 'suspicious encoding marker at common.greeting');
    $reject('App/Support/lang/cs.json', '{"common.empty":"x"}', 'invalid key common.empty');
    $reject('App/Support/lang/cs.json', '[]', 'catalog must be an object');
    $reject('App/Support/lang/cs.json', '{', 'Syntax error');
    $reject('App/Support/lang/cs.json', '{"common":{"empty":""},"other":{"empty":""}}', 'missing key common.greeting');
    $reject('App/Support/lang/cs.json', str_replace('"other":{', '"extra":"x","other":{', $app), 'unknown key extra');
    $reject('App/Support/lang/cs.json', str_replace('{count}', '{total}', $app), 'placeholder mismatch at common.greeting');
    $reject('App/Support/lang/cs.json', str_replace('{name}', '{username}', $app), 'placeholder mismatch at common.greeting');
    $reject('App/Mail/lang/cs.json', str_replace('{username}', '{user}', $emails), 'placeholder mismatch at templates.welcome.body');
    $reset();
    file_put_contents($directory . '/App/Support/lang/cs.json', str_replace('{name}: {count}', '{count} / {name} / {name}', $app));
    $validate($directory);
    $checks++;
    $reset();
    unlink($directory . '/App/Mail/lang/cs.json');
    try {
        $validate($directory);
        throw new LogicException('Missing catalog accepted');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'App/Mail/lang/cs.json: missing catalog')) { throw $error; }
        $checks++;
    }
    $reset();
    foreach (['en', 'cs'] as $locale) {
        file_put_contents($directory . '/App/Example/lang/' . $locale . '.json', '{"common":{"greeting":"Hello {name}: {count}"}}');
    }
    try {
        $validate($directory);
        throw new LogicException('Duplicate module ownership accepted');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'duplicate ownership of common.greeting')) { throw $error; }
        $checks++;
    }
    foreach (['en', 'cs'] as $locale) {
        file_put_contents($directory . '/App/Example/lang/' . $locale . '.json', '{"common":{"owned":"Module"}}');
    }
    $merged = $validate($directory);
    if ($merged['cs']['app']['common.owned'] !== 'Module' || !isset($merged['cs']['app']['common.greeting'])) {
        throw new RuntimeException('Modules may share namespaces, never individual keys.');
    }
    $checks++;
    $reject('App/Example/lang/cs.json', '{"common":{"greeting":"Hello {name}: {count}"}}', 'missing key common.owned');
    file_put_contents($directory . '/App/Example/lang/cs.json', '{"common":{"owned":"Module"}}');
    unlink($directory . '/App/Example/lang/cs.json');
    try {
        $validate($directory);
        throw new LogicException('Incomplete module locale accepted');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'App/Example/lang/cs.json: missing catalog')) { throw $error; }
        $checks++;
    }
    $reject('lang/cs.json', '{"name":"Test","name":"Duplicate"}', 'duplicate key name');
    file_put_contents($directory . '/lang/cs.json', '{"name":"Test"}');
    $real = $validate(dirname(__DIR__, 2));
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
        foreach (array_keys($real) as $locale) {
            \TinyCat\Support\ClientAssets::catalog($scope, $locale);
            $checks++;
        }
    }
    $validateClients = require dirname(__DIR__, 2) . '/tools/client-translations.php';
    $featuresProperty = new ReflectionProperty(\TinyCat\Support\ClientAssets::class, 'features');
    $saved = $featuresProperty->getValue();
    try {
        foreach (['missing.catalog_entry', null] as $dependency) {
            $features = $saved;
            $features['/'] = [['entries' => ['assets/modules/users/views.js'], 'translations' => $dependency === null ? [] : [$dependency]]];
            $featuresProperty->setValue(null, $features);
            try {
                $validateClients($real);
                throw new LogicException('Invalid client declaration accepted');
            } catch (RuntimeException $error) {
                $expected = $dependency === null ? 'undeclared' : 'Missing client translation: missing.catalog_entry';
                if (!str_contains($error->getMessage(), $expected)) { throw $error; }
                $checks++;
            }
        }
    } finally {
        $featuresProperty->setValue(null, $saved);
    }
} finally {
foreach (['Support', 'Mail', 'Example'] as $owner) {
        foreach (['en', 'cs'] as $locale) {
            $file = $directory . '/App/' . $owner . '/lang/' . $locale . '.json';
            if (is_file($file)) { unlink($file); }
        }
        rmdir($directory . '/App/' . $owner . '/lang');
        rmdir($directory . '/App/' . $owner);
    }
    foreach (['en', 'cs'] as $locale) { unlink($directory . '/lang/' . $locale . '.json'); }
    rmdir($directory . '/lang');
    rmdir($directory . '/App');
    rmdir($directory);
}
echo 'PASS source translation validation and client declarations (' . $checks . " checks)\n";
