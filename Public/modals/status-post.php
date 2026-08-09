<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = (array) ($item ?? []);
$comments = is_array($comments ?? null) ? $comments : [];
$user = isset($user) && is_array($user) ? $user : null;
$action = (string) ($action ?? '');
$contentId = (int) ($item['id'] ?? 0);

if ($contentId < 1) {
    http_response_code(404);
    return;
}

$authorId = (int) ($item['author_id'] ?? 0);
$authorName = trim((string) ($item['author_name'] ?? ''));
$publishedAt = (string) ($item['published_at'] ?? '');
$view = is_array($item['_view'] ?? null) ? $item['_view'] : [];
$modalId = status_post_modal_id($contentId);
$bodyHtml = (string) ($view['body_html'] ?? '');

ob_start();
?>
<article class="status-post-detail">
    <div class="status-header">
        <a class="avatar" href="<?= e($authorId > 0 ? (string) ($view['author_url'] ?? '#') : '#') ?>" aria-label="<?= e($authorName) ?>">
            <?= part('user/avatar', ['user' => $item, 'alt' => $authorName]) ?>
        </a>
        <div class="status-author">
            <?php if ($authorId > 0 && $authorName !== ''): ?>
                <a href="<?= e((string) ($view['author_url'] ?? '#')) ?>"><?= e($authorName) ?></a>
            <?php elseif ($authorName !== ''): ?>
                <strong><?= e($authorName) ?></strong>
            <?php endif; ?>
            <?php if ($publishedAt !== ''): ?>
                <time class="public-content-meta" datetime="<?= e((string) (($view['time'] ?? [])['iso'] ?? '')) ?>"><?= e((string) (($view['time'] ?? [])['label'] ?? '')) ?></time>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($bodyHtml !== ''): ?>
        <div class="status-body"><?= $bodyHtml ?></div>
    <?php endif; ?>
    <?= part('status/image', ['item' => $item]) ?>
    <?= part('status/links', ['item' => $item]) ?>
    <?= part('status/actions', [
        'item' => $item,
        'user' => $user,
        'action' => $action,
        'open_comments_modal' => false,
    ]) ?>
    <?= part('status/comments-thread', [
        'item' => $item,
        'comments' => $comments,
        'user' => $user,
        'action' => $action,
        'context' => 'modal-' . $contentId,
    ]) ?>
</article>
<?php
$body = trim((string) ob_get_clean());

echo render('modals/layout', [
    'id' => $modalId,
    'title' => t('account.status_thread_title'),
    'modalClass' => 'status-post-modal modal-mobile-fullscreen',
    'size' => 'modal-panel-lg status-post-modal-panel',
    'bodyClass' => 'status-post-modal-body',
    'body' => $body,
]);
