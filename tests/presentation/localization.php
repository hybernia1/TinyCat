<?php
declare(strict_types=1);

require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

use TinyCat\Support\Localization;

(new ReflectionProperty(Localization::class, 'translations'))->setValue(null, [
    'en' => ['test' => ['fallback' => 'English', 'empty' => 'Default', 'params' => '{a} {b} :count :counter']],
    'cs' => ['test' => ['empty' => '', 'nested' => ['label' => 'Local']]],
]);
$checks = 0;
$check = static function (bool $value) use (&$checks): void {
    $checks++;
    if (!$value) { throw new RuntimeException('Localization regression #' . $checks); }
};
$check(Localization::t('test.fallback', [], 'cs') === 'English');
$check(Localization::catalog('cs')['test.fallback'] === 'English');
$check(Localization::t('test.empty', [], 'cs') === '' && Localization::catalog('cs')['test.empty'] === '');
$check(Localization::catalog('cs')['test.nested.label'] === 'Local');
$check(Localization::t('missing.translation', [], 'cs') === 'missing.translation');
$check(Localization::t('test.params', ['a' => '{b}', 'b' => '$&\\', 'count' => 2, 'counter' => 3], 'cs') === '{b} $&\\ 2 3');
echo 'PASS localization fallback and literal interpolation (' . $checks . " checks)\n";
