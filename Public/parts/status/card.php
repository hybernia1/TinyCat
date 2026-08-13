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
$authorId = (int) ($item['author_id'] ?? 0);
$authorName = trim((string) ($item['author_name'] ?? ''));
$publishedAt = (string) ($item['published_at'] ?? '');
$view = is_array($item['_view'] ?? null) ? $item['_view'] : [];
$url = $authorId > 0 ? (string) ($view['author_url'] ?? '#') . '#' . status_anchor($contentId) : '#';
$bodyHtml = (string) ($view['body_html'] ?? '');
$anchor = (string) ($anchor ?? status_anchor($contentId));
$openModal = (bool) ($open_modal ?? true);
$openCommentsModal = (bool) ($open_comments_modal ?? true);

if ($contentId < 1) {
    return '';
}
?>
<article class="card status-card"<?= $anchor !== '' ? ' id="' . e($anchor) . '"' : '' ?> data-status-id="<?= e($contentId) ?>" data-status-url="<?= e(status_url($contentId)) ?>" data-status-action="<?= e($action) ?>" data-modal-parent-open="<?= e(status_post_modal_id($contentId)) ?>" data-modal-parent-url="<?= e(status_post_modal_url($contentId, $action)) ?>">
    <div class="card-body status-card-body">
        <div class="status-header">
            <a class="avatar" href="<?= e($url) ?>" aria-label="<?= e($authorName) ?>">
                <?= part('user/avatar', ['user' => $item, 'alt' => $authorName]) ?>
            </a>
            <div class="status-author">
                <?php if ($authorId > 0 && $authorName !== ''): ?>
                    <a href="<?= e((string) ($view['author_url'] ?? '#')) ?>"><?= e($authorName) ?></a>
                <?php endif; ?>
                <?php if ($publishedAt !== ''): ?>
                    <?= part('status/time-link', [
                        'published_at' => $publishedAt,
                        'content_id' => $contentId,
                        'open_modal' => $openModal,
                        'item' => $item,
                    ]) ?>
                <?php endif; ?>
            </div>
            <?= part('status/manage-actions', ['item' => $item, 'user' => $user, 'action' => $action]) ?>
        </div>
        <?php if ($bodyHtml !== ''): ?>
            <div class="status-body"><?= $bodyHtml ?></div>
        <?php endif; ?>
        <?= part('status/image', ['item' => $item, 'open_modal' => $openModal]) ?>
        <?= part('status/links', ['item' => $item]) ?>
        <?= part('status/actions', [
            'item' => $item,
            'user' => $user,
            'action' => $action,
            'open_comments_modal' => $openCommentsModal,
        ]) ?>
    </div>
</article>
