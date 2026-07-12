<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = (array) ($item ?? []);
$user = isset($user) && is_array($user) ? $user : null;
$action = (string) ($action ?? '');
$contentId = (int) ($item['id'] ?? 0);

if ($contentId < 1) {
    http_response_code(404);
    return;
}

$authorId = (int) ($item['author_id'] ?? 0);
$authorName = trim((string) ($item['author_name'] ?? ''));
$avatarUrl = user_avatar_url($item);
$createdAt = (string) ($item['created_at'] ?? '');
$modalId = status_post_modal_id($contentId);
$bodyHtml = render_status_body($item);

ob_start();
?>
<article class="status-post-detail">
    <div class="status-header">
        <a class="avatar" href="<?= e($authorId > 0 ? author_url($authorId) : '#') ?>" aria-label="<?= e($authorName) ?>">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?= e($avatarUrl) ?>" alt="<?= e($authorName) ?>" loading="lazy">
            <?php else: ?>
                <?= icon('user') ?>
            <?php endif; ?>
        </a>
        <div class="status-author">
            <?php if ($authorId > 0 && $authorName !== ''): ?>
                <a href="<?= e(author_url($authorId)) ?>"><?= e($authorName) ?></a>
            <?php elseif ($authorName !== ''): ?>
                <strong><?= e($authorName) ?></strong>
            <?php endif; ?>
            <?php if ($createdAt !== ''): ?>
                <time class="public-content-meta" datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($bodyHtml !== ''): ?>
        <div class="status-body"><?= $bodyHtml ?></div>
    <?php endif; ?>
    <?= status_links_html($item) ?>
    <?= status_actions($item, $user, $action) ?>
    <?= status_comment_thread_section($item, $user, $action, 'modal-' . $contentId) ?>
</article>
<?php
$body = trim((string) ob_get_clean());

echo render('modals/layout', [
    'id' => $modalId,
    'title' => t('account.status_thread_title'),
    'size' => 'modal-panel-lg status-post-modal-panel',
    'bodyClass' => 'status-post-modal-body',
    'body' => $body,
]);
