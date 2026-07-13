<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$user = is_array($user ?? null) ? $user : null;
$action = (string) ($action ?? '/');
$openCommentsModal = (bool) ($open_comments_modal ?? true);
$contentId = (int) ($item['id'] ?? 0);
$likesCount = (int) ($item['likes_count'] ?? 0);
$commentsCount = array_key_exists('comments_count', $item)
    ? (int) ($item['comments_count'] ?? 0)
    : status_comment_count($contentId);
$userId = (int) ($user['id'] ?? 0);
$liked = $userId > 0 && status_user_liked($contentId, $userId);
$loginUrl = status_login_url($contentId > 0 ? '#' . status_anchor($contentId) : '', $action);

if ($contentId < 1) {
    return '';
}
?>
<div class="status-reactions">
    <?php if ($user !== null): ?>
        <form method="post" action="<?= e(status_api_url('react', ['id' => $contentId])) ?>" data-status-form data-status-id="<?= e($contentId) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="react">
            <input type="hidden" name="id" value="<?= e($contentId) ?>">
            <button class="btn btn-ghost btn-sm status-reaction<?= $liked ? ' is-active' : '' ?>" type="submit" title="<?= et('account.status_like') ?>" data-status-like-button>
                <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?><?= icon('thumb-up-filled', 'icon status-like-icon status-like-icon-filled') ?> <span data-status-count="likes"><?= e($likesCount) ?></span>
            </button>
        </form>
    <?php else: ?>
        <a class="btn btn-ghost btn-sm status-reaction" href="<?= e($loginUrl) ?>" aria-label="<?= et('account.status_like') ?>" title="<?= et('account.status_like') ?>">
            <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?> <span data-status-count="likes"><?= e($likesCount) ?></span>
        </a>
    <?php endif; ?>
    <?php if ($openCommentsModal): ?>
        <a class="btn btn-ghost btn-sm status-reaction" href="<?= e(status_url($contentId) . '#status-comments-thread-' . $contentId) ?>" data-modal-open aria-label="<?= et('account.status_comments') ?>">
            <?= icon('message-circle') ?> <span data-status-count="comments"><?= e($commentsCount) ?></span>
        </a>
    <?php else: ?>
        <a class="btn btn-ghost btn-sm status-reaction" href="#status-comments-thread-<?= e($contentId) ?>" aria-label="<?= et('account.status_comments') ?>">
            <?= icon('message-circle') ?> <span data-status-count="comments"><?= e($commentsCount) ?></span>
        </a>
    <?php endif; ?>
</div>
