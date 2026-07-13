<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$statusId = max(0, (int) get('id', 0));
$current = status_url($statusId);
$compact = (string) get('compact', '') === '1';
$pageAction = $current . ($compact ? '?compact=1' : '');

if ($statusId < 1) {
    tc_status_not_found();
    return;
}

if (method() === 'GET' && route_path() !== $current) {
    redirect($current, 301);
}

$item = public_status_item($statusId);

if ($item === null) {
    tc_status_not_found();
    return;
}

$statusTitle = status_meta_title($item);

layout('layout', [
    'title' => $statusTitle,
    'current' => $current,
    'meta' => [
        'title' => $statusTitle,
        'description' => status_meta_description($item),
        'url' => $current,
        'image' => status_meta_image($item),
        'type' => 'article',
        'published_time' => (string) ($item['created_at'] ?? ''),
        'author' => (string) ($item['author_name'] ?? ''),
    ],
], static function () use ($item, $current, $compact, $pageAction): void {
    $authorId = (int) ($item['author_id'] ?? 0);
    $authorName = trim((string) ($item['author_name'] ?? ''));
    $createdAt = (string) ($item['created_at'] ?? '');
    $contentId = (int) ($item['id'] ?? 0);
    ?>
    <section class="public-layout">
        <main class="home-feed-section stack stack-gap-14">
            <article class="card status-card status-permalink-card" id="<?= e(status_anchor($contentId)) ?>">
                <div class="card-body stack stack-gap-12">
                    <div class="status-header">
                        <a class="avatar" href="<?= e($authorId > 0 ? author_url($authorId) : '#') ?>" aria-label="<?= e($authorName) ?>">
                            <?= user_avatar_html($item, $authorName) ?>
                        </a>
                        <div class="status-author">
                            <?php if ($authorId > 0 && $authorName !== ''): ?>
                                <a href="<?= e(author_url($authorId)) ?>"><?= e($authorName) ?></a>
                            <?php endif; ?>
                            <?php if ($createdAt !== ''): ?>
                                <?= status_time_button($createdAt, $contentId, false) ?>
                            <?php endif; ?>
                        </div>
                        <?= status_manage_actions($item, auth(), $pageAction) ?>
                    </div>

                    <?php $bodyHtml = render_status_body($item); ?>
                    <?php if ($bodyHtml !== ''): ?>
                        <div class="status-body"><?= $bodyHtml ?></div>
                    <?php endif; ?>
                    <?= status_links_html($item) ?>

                    <?= status_actions($item, auth(), $pageAction, false) ?>
                    <?= status_comment_thread_section($item, auth(), $pageAction, 'status-' . $contentId) ?>
                </div>
            </article>
        </main>
        <?php if (!$compact): ?><?= public_sidebar() ?><?php endif; ?>
    </section>
    <?php
});

function tc_status_not_found(): void
{
    http_response_code(404);
    layout('layout', [
        'title' => t('public.status_not_found'),
        'current' => '/status',
        'meta' => [
            'description' => t('public.status_not_found'),
            'url' => '/status',
            'robots' => 'noindex,follow',
        ],
    ], static function (): void {
        ?>
        <div class="alert alert-info"><?= et('public.status_not_found') ?></div>
        <?php
    });
}
