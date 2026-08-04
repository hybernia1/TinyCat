<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$result = is_array($result ?? null) ? $result : [];
$details = [];

foreach ($result as $key => $value) {
    if (in_array($key, ['task', 'changed', 'has_more', 'stalled', 'done', 'batch_size', 'duration_ms'], true) || is_array($value) || is_object($value)) {
        continue;
    }

    $details[(string) $key] = (string) $value;
}
?>
<?php if ($details === []): ?>
    <span class="text-muted"><?= et('maintenance.no_details') ?></span>
<?php else: ?>
    <div class="cluster gap-2">
        <?php foreach ($details as $key => $value): ?>
            <span class="badge"><?= e(str_replace('_', ' ', $key)) ?>: <?= e($value) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
