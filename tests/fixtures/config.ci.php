<?php
declare(strict_types=1);

return [
    'app' => [
        'url' => 'http://localhost',
        'debug' => true,
    ],
    'cache' => [
        'driver' => 'filesystem',
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'tinycat_ci',
        'user' => 'root',
        'password' => 'tinycat-ci',
        'charset' => 'utf8mb4',
    ],
    'install' => [
        'locale' => 'en',
        'complete' => false,
    ],
    'security' => [
        'captcha' => [
            'enabled' => false,
        ],
    ],
];
