<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$value = (string) ($value ?? '');
$rows = max(1, min(8, (int) ($rows ?? 1)));
$placeholder = (string) ($placeholder ?? t('account.status_comment_placeholder'));
$label = (string) ($label ?? t('account.status_comment'));
$wrapperClass = trim('status-comment-editor ' . (string) ($wrapper_class ?? ''));
?>
<div class="<?= e($wrapperClass) ?>" data-status-editor>
    <textarea class="textarea status-comment-input" name="comment" rows="<?= e($rows) ?>" maxlength="2000" placeholder="<?= e($placeholder) ?>" aria-label="<?= e($label) ?>" required data-status-editor-source data-status-suggest-url="/api/status-suggest" data-status-placeholder="<?= e($placeholder) ?>" data-status-editor-counter-disabled="true"><?= e($value) ?></textarea>
</div>
