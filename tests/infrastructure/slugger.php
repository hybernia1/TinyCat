<?php
declare(strict_types=1);

define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Text\Slugger;

$assert = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
foreach (['Černý čaj 100 g' => 'cerny-caj-100-g', '  Hello___WORLD / 42! ' => 'hello-world-42', '' => '', '!!!' => '', '123' => '123', 'Tea & Coffee' => 'tea-coffee'] as $input => $expected) {
    $result = Slugger::slug((string) $input);
    $assert($result === $expected, 'ASCII segment conversion: ' . $expected);
    $assert(Slugger::slug($result) === $result, 'Slug conversion is idempotent.');
}
try { Slugger::slug("\xFF"); throw new RuntimeException('Invalid UTF-8 accepted.'); }
catch (InvalidArgumentException) {}
echo "PASS pure iconv slugger\n";
