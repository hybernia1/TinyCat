<?php
declare(strict_types=1);

require_once __DIR__ . '/component-syntax.php';
$target = dirname(__DIR__, 2) . '/docs/reference/component-catalog.schema.json';
file_put_contents($target, json_encode(\TinyCat\Tools\Quality\ComponentSyntax::schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Generated component authoring schema.\n";
