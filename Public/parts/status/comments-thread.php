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
$comments = $contentId > 0 ? status_comments($contentId) : [];

if ($contentId < 1 || ($comments === [] && $user === null)) {
    return '';
}
?>
<section class="status-comments status-comments-thread" id="status-comments-thread-<?= e($contentId) ?>">
    <?php if ($user !== null): ?>
        <?= status_comment_form($contentId, $action, $user, 0, '', $context) ?>
    <?php endif; ?>

    <div class="status-comment-list" data-status-comment-list data-status-id="<?= e($contentId) ?>">
        <?php foreach ($comments as $comment): ?>
            <?= status_comment_item($comment, $user, $action, 0, $context, true, true) ?>
        <?php endforeach; ?>
    </div>

    <?php if ($user === null): ?>
        <a class="btn btn-secondary btn-sm status-comment-login" href="<?= e(status_login_url('#status-comments-thread-' . $contentId, $action)) ?>">
            <?= icon('login') ?> <span><?= et('account.status_comment_login') ?></span>
        </a>
    <?php endif; ?>
</section>
