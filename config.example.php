<?php
declare(strict_types=1);

return [
    'app' => [
        // 'on': show errors; 'off': no diagnostics; 'log': storage/debug.log.
        // Keep these values quoted. Legacy true/false remain supported.
        'debug' => 'off',
        'url' => 'https://example.test',
    ],
    'database' => [
        'host' => 'localhost',
        'name' => 'tinycat',
        'user' => 'tinycat',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'cache' => ['driver' => 'filesystem'],
    'install' => ['locale' => 'en', 'complete' => false],
];
