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
$authorId = (int) ($item['author_id'] ?? $item['user_id'] ?? 0);
$authorName = trim((string) ($item['author_name'] ?? ''));
$avatarUrl = user_avatar_url($item);
$createdAt = (string) ($item['created_at'] ?? '');
$url = $authorId > 0 ? author_url($authorId) . '#' . status_anchor($contentId) : '#';
$bodyHtml = render_status_body($item);

if ($contentId < 1) {
    return '';
}
?>
<article class="card status-card" id="<?= e(status_anchor($contentId)) ?>" data-status-action="<?= e($action) ?>">
    <div class="card-body status-card-body">
        <div class="status-header">
            <a class="avatar" href="<?= e($url) ?>" aria-label="<?= e($authorName) ?>">
                <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($authorName) ?>" loading="lazy">
                <?php else: ?>
                    <?= icon('user') ?>
                <?php endif; ?>
            </a>
            <div class="status-author">
                <?php if ($authorId > 0 && $authorName !== ''): ?>
                    <a href="<?= e(author_url($authorId)) ?>"><?= e($authorName) ?></a>
                <?php endif; ?>
                <?php if ($createdAt !== ''): ?>
                    <?= status_time_button($createdAt, $contentId, true, $action) ?>
                <?php endif; ?>
            </div>
            <?= status_manage_actions($item, $user, $action) ?>
        </div>
        <?php if ($bodyHtml !== ''): ?>
            <div class="status-body"><?= $bodyHtml ?></div>
        <?php endif; ?>
        <?= status_links_html($item) ?>
        <?= status_actions($item, $user, $action) ?>
        <?= status_comments_section($item, $user, $action) ?>
    </div>
</article>
