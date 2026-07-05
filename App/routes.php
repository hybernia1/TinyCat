<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

api_route('GET', 'ping', static function (): array {
    return [
        'app' => config('app.name', 'TinyCat'),
        'version' => config('app.version', '1.0.0'),
        'time' => date(DATE_ATOM),
    ];
});
