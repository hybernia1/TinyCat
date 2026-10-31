<?php
declare(strict_types=1);
require_once __DIR__ . '/Csfd.php';

return [
    'news' => static fn(array $config): array => ['type' => 'rss', 'data' => [
        'title' => 'ČSFD – Filmové novinky', 'url' => 'https://www.csfd.cz/novinky/',
        'description' => 'Krátké anotace filmových novinek z ČSFD.cz. Celé články najdete na webu zdroje.',
        'language' => 'cs-CZ', 'items' => Csfd::news($config),
    ]],
];
