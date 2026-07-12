<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$contentId = (int) ($content_id ?? 0);
$action = (string) ($action ?? '/');
$user = is_array($user ?? null) ? $user : [];
$parentId = (int) ($parent_id ?? 0);
$mention = (string) ($mention ?? '');
$context = (string) ($context ?? '');
$avatarUrl = user_avatar_url($user);
$isReply = $parentId > 0;
$label = et($isReply ? 'account.status_reply' : 'account.status_comment');

if ($contentId < 1 || $user === []) {
    return '';
}
?>
<form class="status-comment-form<?= $isReply ? ' is-reply' : '' ?>" method="post" action="<?= e(status_api_url('comment', ['id' => $contentId])) ?>" data-status-form data-status-id="<?= e($contentId) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="comment">
    <input type="hidden" name="id" value="<?= e($contentId) ?>">
    <input type="hidden" name="parent_id" value="<?= e($parentId) ?>">
    <input type="hidden" name="context" value="<?= e($context) ?>">
    <div class="avatar avatar-sm">
        <?php if ($avatarUrl !== ''): ?>
            <img src="<?= e($avatarUrl) ?>" alt="<?= e(user_display_name($user)) ?>" loading="lazy">
        <?php else: ?>
            <?= icon('user') ?>
        <?php endif; ?>
    </div>
    <div class="status-comment-input-shell">
        <div class="status-comment-editor" data-status-editor>
            <textarea class="textarea status-comment-input" name="comment" rows="1" maxlength="2000" placeholder="<?= et($isReply ? 'account.status_reply_placeholder' : 'account.status_comment_placeholder') ?>" aria-label="<?= $label ?>" required data-status-editor-source data-status-suggest-url="/api/status-suggest" data-status-placeholder="<?= et($isReply ? 'account.status_reply_placeholder' : 'account.status_comment_placeholder') ?>" data-status-editor-counter-disabled="true"><?= e($mention) ?></textarea>
        </div>
        <button class="btn btn-primary btn-icon btn-sm status-comment-submit" type="submit" title="<?= $label ?>" aria-label="<?= $label ?>">
            <?= icon('send') ?>
        </button>
    </div>
</form>
