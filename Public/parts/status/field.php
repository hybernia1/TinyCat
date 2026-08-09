<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$tags = (string) ($tags_json ?? '[]');
?>
<div class="field status-field" data-status-editor>
    <textarea class="textarea status-textarea" name="body" rows="4" maxlength="2000" placeholder="<?= et('account.status_body') ?>" aria-label="<?= et('account.status_body') ?>" data-status-editor-source data-status-tags="<?= e((string) $tags) ?>" data-status-suggest-url="/api/status-suggest" data-status-placeholder="<?= et('account.status_body') ?>" data-status-counter="<?= et('account.status_counter') ?>"><?= e($item['body'] ?? '') ?></textarea>
</div>
