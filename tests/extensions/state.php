<?php
declare(strict_types=1);

define('TINYCAT', true);

$root = dirname(__DIR__, 2);
require_once $root . '/App/Core.php';
require_once $root . '/App/UserRoles.php';
require_once $root . '/App/ExtensionRegistry.php';
require_once $root . '/App/ExtensionLoader.php';

$scenario = (string) ($argv[1] ?? '');
if (!in_array($scenario, ['enabled', 'disabled'], true)) {
    fwrite(STDERR, "Expected enabled or disabled scenario.\n");
    exit(2);
}

$expected = $scenario === 'enabled';
ExtensionLoader::boot($root . '/Extensions', ['bots' => $expected]);

$available = ExtensionLoader::available()['bots'] ?? [];
$valid = ($available['requested_enabled'] ?? null) === $expected
    && ($available['enabled'] ?? null) === $expected
    && ExtensionRegistry::has('bots') === $expected
    && class_exists(Bots::class, false) === $expected
    && in_array('bot_sources', ExtensionRegistry::requiredTables(), true) === $expected
    && isset(ExtensionRegistry::scheduledTasks()['feeds']) === $expected;

if (!$valid) {
    fwrite(STDERR, 'Extension state did not produce the expected runtime registration.' . PHP_EOL);
    exit(1);
}

echo 'PASS extension state: ' . $scenario . PHP_EOL;
