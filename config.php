<?php 
declare(strict_types=1);
//app config file

return [
    'app' => [
        'name' => 'TinyCat',
        'version' => '1.0.0',
        'debug' => true,
        'locale' => 'cs',
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'name' => 'micro',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'i18n' => [
        'locale' => 'cs',
        'fallback' => 'en',
        'directory' => __DIR__ . '/lang',
    ],
    'assets' => [
        'url' => '/assets',
        'directory' => __DIR__ . '/assets',
        'icons' => 'icons.svg',
        'version' => true,
    ],
    'directory' => [
        'base' => __DIR__,
        'app' => __DIR__ . '/App',
        'lang' => __DIR__ . '/lang',
        'assets' => __DIR__ . '/assets',
        'css' => __DIR__ . '/assets/css',
        'js' => __DIR__ . '/assets/js',
    ],
];
