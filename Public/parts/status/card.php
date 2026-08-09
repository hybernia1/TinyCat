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
$createdAt = (string) ($item['created_at'] ?? '');
$view = is_array($item['_view'] ?? null) ? $item['_view'] : [];
$url = $authorId > 0 ? (string) ($view['author_url'] ?? '#') . '#' . status_anchor($contentId) : '#';
$bodyHtml = (string) ($view['body_html'] ?? '');

if ($contentId < 1) {
    return '';
}
?>
<article class="card status-card" id="<?= e(status_anchor($contentId)) ?>" data-status-id="<?= e($contentId) ?>" data-status-url="<?= e(status_url($contentId)) ?>" data-status-action="<?= e($action) ?>" data-modal-parent-open="<?= e(status_post_modal_id($contentId)) ?>" data-modal-parent-url="<?= e(status_post_modal_url($contentId, $action)) ?>">
    <div class="card-body status-card-body">
        <div class="status-header">
            <a class="avatar" href="<?= e($url) ?>" aria-label="<?= e($authorName) ?>">
                <?= part('user/avatar', ['user' => $item, 'alt' => $authorName]) ?>
            </a>
            <div class="status-author">
                <?php if ($authorId > 0 && $authorName !== ''): ?>
                    <a href="<?= e((string) ($view['author_url'] ?? '#')) ?>"><?= e($authorName) ?></a>
                <?php endif; ?>
                <?php if ($createdAt !== ''): ?>
                    <?= part('status/time-link', [
                        'created_at' => $createdAt,
                        'content_id' => $contentId,
                        'open_modal' => true,
                        'item' => $item,
                    ]) ?>
                <?php endif; ?>
            </div>
            <?= part('status/manage-actions', ['item' => $item, 'user' => $user, 'action' => $action]) ?>
        </div>
        <?php if ($bodyHtml !== ''): ?>
            <div class="status-body"><?= $bodyHtml ?></div>
        <?php endif; ?>
        <?= part('status/image', ['item' => $item, 'open_modal' => true]) ?>
        <?= part('status/links', ['item' => $item]) ?>
        <?= part('status/actions', ['item' => $item, 'user' => $user, 'action' => $action]) ?>
    </div>
</article>
