<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$commentId = (int) ($comment_id ?? 0);
$likesCount = (int) ($likes_count ?? 0);
$liked = (bool) ($liked ?? false);
$user = is_array($user ?? null) ? $user : null;
$contentId = (int) ($content_id ?? 0);

if ($commentId < 1) {
    return '';
}
?>
<?php if ($user !== null): ?>
    <form class="status-comment-like" method="post" action="<?= e(status_api_url('comment-like', ['comment_id' => $commentId])) ?>" data-status-form<?= $contentId > 0 ? ' data-status-id="' . e($contentId) . '"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="comment_like">
        <input type="hidden" name="comment_id" value="<?= e($commentId) ?>">
        <button class="link-button status-comment-like-button<?= $liked ? ' is-active' : '' ?>" type="submit" data-comment-like-button data-comment-id="<?= e($commentId) ?>">
            <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?><?= icon('thumb-up-filled', 'icon status-like-icon status-like-icon-filled') ?> <span data-comment-like-count data-comment-id="<?= e($commentId) ?>"><?= e($likesCount) ?></span>
        </button>
    </form>
<?php else: ?>
    <span class="status-comment-like-button" aria-label="<?= et('account.status_like') ?>" data-comment-like-button data-comment-id="<?= e($commentId) ?>">
        <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?> <span data-comment-like-count data-comment-id="<?= e($commentId) ?>"><?= e($likesCount) ?></span>
    </span>
<?php endif; ?>
