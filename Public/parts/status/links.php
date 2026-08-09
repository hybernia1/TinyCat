<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$links = is_array(($item['_view'] ?? [])['links'] ?? null) ? $item['_view']['links'] : [];

if ($links === []) {
    return '';
}
?>
<div class="status-links">
    <?php foreach ($links as $link): ?>
        <?= part('status/link-card', ['link' => $link]) ?>
    <?php endforeach; ?>
</div>
