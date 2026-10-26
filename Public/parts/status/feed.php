<?php
declare(strict_types=1);

use TinyCat\Support\View;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$items = is_array($items ?? null) ? $items : [];
$action = (string) ($action ?? '/');
$user = is_array($user ?? null) ? $user : null;
?>
<?php foreach ($items as $item): ?>
    <?= \TinyCat\Status\Views::card([
        'item' => is_array($item) ? $item : [],
        'action' => $action,
        'user' => $user,
    ]) ?>
<?php endforeach; ?>
