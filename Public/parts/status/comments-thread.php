<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$user = is_array($user ?? null) ? $user : null;
$action = (string) ($action ?? '/');
$context = (string) ($context ?? '');
$contentId = (int) ($item['id'] ?? 0);
$comments = is_array($comments ?? null) ? $comments : [];

if ($contentId < 1 || ($comments === [] && $user === null)) {
    return '';
}
?>
<section class="status-comments status-comments-thread" id="status-comments-thread-<?= e($contentId) ?>">
    <?php if ($user !== null): ?>
        <?= part('status/comment-form', [
            'content_id' => $contentId,
            'action' => $action,
            'user' => $user,
            'context' => $context,
        ]) ?>
    <?php endif; ?>

    <div class="status-comment-list" data-status-comment-list data-status-id="<?= e($contentId) ?>">
        <?php foreach ($comments as $comment): ?>
            <?= part('status/comment-item', [
                'comment' => $comment,
                'user' => $user,
                'action' => $action,
                'depth' => 0,
                'context' => $context,
                'show_replies' => true,
                'show_reply_form' => true,
            ]) ?>
        <?php endforeach; ?>
    </div>

    <?php if ($user === null): ?>
        <?php $loginUrl = status_login_url('#status-comments-thread-' . $contentId, $action); ?>
        <a class="btn btn-secondary btn-sm status-comment-login" href="<?= e($loginUrl) ?>" data-modal-open="<?= e(auth_modal_id()) ?>" data-modal-url="<?= e(auth_modal_url('login', auth_next_from_url($loginUrl))) ?>">
            <?= icon('login') ?> <span><?= et('account.status_comment_login') ?></span>
        </a>
    <?php endif; ?>
</section>
