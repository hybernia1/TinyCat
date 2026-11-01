<?php
declare(strict_types=1);
require_once __DIR__ . '/Csfd.php';

return [
    'premieres' => static fn(array $config): array => ['type' => 'rss', 'data' => [
        'title' => 'ČSFD – Kino premiéry', 'url' => 'https://www.csfd.cz/kino/premiery/',
        'description' => 'Filmové premiéry v českých kinech v aktuálním měsíci podle ČSFD.cz.',
        'language' => 'cs-CZ', 'items' => Csfd::premieres($config),
    ]],
    'news' => static fn(array $config): array => ['type' => 'rss', 'data' => [
        'title' => 'ČSFD – Filmové novinky', 'url' => 'https://www.csfd.cz/novinky/',
        'description' => 'Krátké anotace filmových novinek z ČSFD.cz. Celé články najdete na webu zdroje.',
        'language' => 'cs-CZ', 'items' => Csfd::news($config),
    ]],
];
