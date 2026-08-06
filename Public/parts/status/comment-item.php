<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$comment = is_array($comment ?? null) ? $comment : [];
$user = is_array($user ?? null) ? $user : null;
$action = (string) ($action ?? '/');
$depth = (int) ($depth ?? 0);
$context = (string) ($context ?? '');
$showReplies = (bool) ($show_replies ?? true);
$showReplyForm = (bool) ($show_reply_form ?? true);
$commentId = (int) ($comment['id'] ?? 0);
$contentId = (int) ($comment['content_id'] ?? 0);
$authorId = (int) ($comment['user_id'] ?? 0);
$authorName = trim((string) ($comment['author_name'] ?? ''));
$createdAt = (string) ($comment['created_at'] ?? '');
$replies = $depth === 0 ? (array) ($comment['replies'] ?? []) : [];
$canEdit = status_comment_can_edit($comment, $user);
$canDelete = status_comment_can_delete($comment, $user);
$hasMenuActions = $canEdit || $canDelete;
$userId = (int) ($user['id'] ?? 0);
$likesCount = array_key_exists('likes_count', $comment)
    ? (int) ($comment['likes_count'] ?? 0)
    : status_comment_like_count($commentId);
$liked = $userId > 0 && status_comment_user_liked($commentId, $userId);
$commentDomId = 'comment-' . ($context !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $context) . '-' : '') . $commentId;
$preview = !$showReplies && !$showReplyForm;

if ($commentId < 1 || $contentId < 1) {
    return '';
}
?>
<article class="status-comment<?= $depth > 0 ? ' is-child' : '' ?>"<?= $preview ? '' : ' id="' . e($commentDomId) . '"' ?> data-comment-id="<?= e($commentId) ?>"<?= $preview ? '' : ' data-content-id="' . e($contentId) . '" data-parent-id="' . e((int) ($comment['parent_id'] ?? 0)) . '"' ?>>
    <a class="avatar avatar-sm" href="<?= e(author_url($authorId)) ?>" aria-label="<?= e($authorName) ?>">
        <?= part('user/avatar', ['user' => $comment, 'alt' => $authorName]) ?>
    </a>
    <div class="status-comment-main">
        <div class="status-comment-bubble">
            <?php if ($authorName !== '' || $hasMenuActions): ?>
                <div class="status-comment-heading">
                    <?php if ($authorName !== ''): ?>
                        <a class="status-comment-author" href="<?= e(author_url($authorId)) ?>"><?= e($authorName) ?></a>
                    <?php endif; ?>
                    <?php if ($createdAt !== ''): ?>
                        <time class="status-comment-heading-time" datetime="<?= e(date_iso($createdAt)) ?>"> · <?= e(datetime($createdAt)) ?></time>
                    <?php endif; ?>
                    <?php if ($hasMenuActions): ?>
                        <details class="context-menu status-comment-menu" data-dismissible-menu>
                            <summary class="btn btn-ghost btn-icon btn-sm" aria-label="<?= et('common.actions') ?>">
                                <?= icon('more') ?>
                            </summary>
                            <div class="context-menu-popover">
                                <?php if ($canEdit): ?>
                                    <button class="context-menu-action" type="button" data-dismissible-menu-action data-modal-open="<?= e(status_comment_edit_modal_id($commentId)) ?>" data-modal-url="<?= e(status_comment_edit_modal_url($commentId)) ?>">
                                        <?= et('common.edit') ?>
                                    </button>
                                    <button class="context-menu-action" type="button" data-dismissible-menu-action data-modal-open="<?= e(status_comment_history_modal_id($commentId)) ?>" data-modal-url="<?= e(status_comment_history_modal_url($commentId)) ?>" data-modal-refresh="true">
                                        <?= et('account.status_comment_history') ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <?= part('status/comment-delete-form', [
                                        'comment_id' => $commentId,
                                        'content_id' => $contentId,
                                        'menu' => true,
                                    ]) ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="status-comment-body"><?= render_mentions((string) ($comment['body'] ?? '')) ?></div>
        </div>
        <div class="status-comment-meta">
            <?= part('status/comment-like-control', [
                'comment_id' => $commentId,
                'likes_count' => $likesCount,
                'liked' => $liked,
                'user' => $user,
                'content_id' => $contentId,
                'comment' => $comment,
            ]) ?>
            <?php if ($user !== null && $showReplyForm): ?>
                <details class="status-reply-details">
                    <summary><?= et('account.status_reply') ?></summary>
                </details>
            <?php endif; ?>
        </div>

        <?php if ($user !== null && $showReplyForm): ?>
            <div class="status-reply-form-container">
                <?= part('status/comment-form', [
                    'content_id' => $contentId,
                    'action' => $action,
                    'user' => $user,
                    'parent_id' => $commentId,
                    'mention' => $depth > 0 ? status_comment_mention($authorName) : '',
                    'context' => $context,
                ]) ?>
            </div>
        <?php endif; ?>

        <?php if ($showReplies && $replies !== []): ?>
            <div class="status-comment-replies" data-comment-replies data-comment-id="<?= e($commentId) ?>">
                <?php foreach ($replies as $reply): ?>
                    <?= part('status/comment-item', [
                        'comment' => $reply,
                        'user' => $user,
                        'action' => $action,
                        'depth' => 1,
                        'context' => $context,
                        'show_replies' => true,
                        'show_reply_form' => $showReplyForm,
                    ]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
