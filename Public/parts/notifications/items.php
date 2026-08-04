<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$notifications = is_array($notifications ?? null) ? $notifications : [];

foreach ($notifications as $notification) {
    echo part('notifications/item', [
        'notification' => is_array($notification) ? $notification : [],
    ]);
}
