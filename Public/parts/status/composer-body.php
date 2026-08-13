<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = is_array($user ?? null) ? $user : [];
$editor = is_array($editor ?? null) ? $editor : [];
$submitName = trim((string) ($submit_name ?? ''));
$submitValue = (string) ($submit_value ?? '1');
$submitLabel = (string) ($submit_label ?? '');
$submitIcon = (string) ($submit_icon ?? 'send');
?>
<div class="status-compose-row">
    <div class="avatar">
        <?= part('user/avatar', ['user' => $user, 'alt' => user_display_name($user)]) ?>
    </div>
    <div class="status-compose-main">
        <?= part('status/field', [
            'item' => (array) ($editor['item'] ?? []),
            'tags_json' => (string) ($editor['tags_json'] ?? '[]'),
        ]) ?>
        <div class="status-compose-footer">
            <div class="status-compose-counter" data-status-editor-meta-slot></div>
            <div class="status-compose-actions">
                <?= part('status/image-field', ['image' => (array) ($editor['image'] ?? [])]) ?>
                <?php if ($submitName !== '' && $submitLabel !== ''): ?>
                    <button class="btn btn-primary btn-icon" type="submit" name="<?= e($submitName) ?>" value="<?= e($submitValue) ?>" title="<?= e($submitLabel) ?>" aria-label="<?= e($submitLabel) ?>"><?= icon($submitIcon) ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
