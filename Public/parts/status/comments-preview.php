<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$user = is_array($user ?? null) ? $user : null;
$action = (string) ($action ?? '/');
$contentId = (int) ($item['id'] ?? 0);
$latestComment = $contentId > 0 ? status_latest_parent_comment($contentId) : null;
$commentsCount = array_key_exists('comments_count', $item)
    ? (int) ($item['comments_count'] ?? 0)
    : status_comment_count($contentId);

if ($contentId < 1 || $latestComment === null) {
    return '';
}
?>
<section class="status-comments">
    <button class="link-button status-comments-open" type="button" data-modal-open="<?= e(status_post_modal_id($contentId)) ?>" data-modal-url="<?= e(status_post_modal_url($contentId, $action)) ?>" data-status-comments-label data-status-id="<?= e($contentId) ?>">
        <?= et('account.status_view_comments', ['count' => $commentsCount]) ?>
    </button>
    <?= status_comment_item($latestComment, $user, $action, 0, 'preview-' . $contentId, false, false) ?>

    <?php if ($user === null): ?>
        <a class="btn btn-secondary btn-sm status-comment-login" href="<?= e(status_login_url('#' . status_anchor($contentId), $action)) ?>">
            <?= icon('login') ?> <span><?= et('account.status_comment_login') ?></span>
        </a>
    <?php endif; ?>
</section>
