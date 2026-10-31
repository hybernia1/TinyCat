<?php
declare(strict_types=1);

// Copy into modules/my-source/module.php. It becomes /feeds/my-source/latest.
// This examples directory is not loaded by the router.
return [
    'latest' => static function (array $config): array {
        // Fetch your API here; keep credentials in config.php.
        // $token = $config['my_source']['token'] ?? '';
        return ['type' => 'rss', 'data' => [
            'title' => 'Můj zdroj',
            'url' => 'https://example.com/',
            'description' => 'Novinky z mého zdroje',
            'language' => 'cs-CZ',
            'items' => [
                [
                    'id' => 'my-source:item:123',
                    'title' => 'Název položky',
                    'description' => 'Popisek položky',
                    'url' => 'https://example.com/items/123',
                    'image_url' => 'https://example.com/images/123.jpg',
                    'categories' => ['Novinky'],
                ],
            ],
        ]];
    },
];
