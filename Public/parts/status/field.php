<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$tags = json_encode(
    status_tag_suggestions(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<div class="field status-field" data-status-editor>
    <textarea class="textarea status-textarea" name="body" rows="4" maxlength="2000" placeholder="<?= et('account.status_body') ?>" aria-label="<?= et('account.status_body') ?>" data-status-editor-source data-status-tags="<?= e((string) $tags) ?>" data-status-suggest-url="/api/status-suggest" data-status-placeholder="<?= et('account.status_body') ?>" data-status-counter="<?= et('account.status_counter') ?>"><?= e($item['body'] ?? '') ?></textarea>
</div>
