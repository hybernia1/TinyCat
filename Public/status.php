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
$statusStructuredImage = status_meta_link_image($item);

layout('layout', [
    'title' => $statusTitle,
    'current' => $current,
    'meta' => [
        'title' => $statusTitle,
        'description' => status_meta_description($item),
        'url' => $current,
        'image' => status_meta_image($item),
        'type' => 'article',
        'published_time' => (string) ($item['published_at'] ?? $item['created_at'] ?? ''),
        'author' => (string) ($item['author_name'] ?? ''),
        'jsonld' => array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'DiscussionForumPosting',
            '@id' => absolute_url($current),
            'url' => absolute_url($current),
            'image' => $statusStructuredImage !== '' ? $statusStructuredImage : null,
            'headline' => $statusTitle,
            'articleBody' => (string) ($item['body'] ?? ''),
            'keywords' => status_tags_from_text((string) ($item['body'] ?? '')),
            'datePublished' => date_iso((string) ($item['published_at'] ?? $item['created_at'] ?? '')),
            'author' => [
                '@type' => 'Person',
                'name' => (string) ($item['author_name'] ?? ''),
                'url' => absolute_url(author_url((int) ($item['author_id'] ?? 0))),
            ],
            'interactionStatistic' => [
                [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/LikeAction',
                    'userInteractionCount' => (int) ($item['likes_count'] ?? 0),
                ],
                [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CommentAction',
                    'userInteractionCount' => (int) ($item['comments_count'] ?? 0),
                ],
            ],
        ], static fn (mixed $value): bool => $value !== null),
    ],
], static function () use ($item, $current, $compact, $pageAction): void {
    $authorId = (int) ($item['author_id'] ?? 0);
    $authorName = trim((string) ($item['author_name'] ?? ''));
    $createdAt = (string) ($item['created_at'] ?? '');
    $contentId = (int) ($item['id'] ?? 0);
    ?>
    <section class="public-layout">
        <div class="home-feed-section stack stack-gap-14">
            <h1 class="sr-only"><?= et('public.status_title') ?></h1>
            <article class="card status-card" id="<?= e(status_anchor($contentId)) ?>">
                <div class="card-body stack stack-gap-12">
                    <div class="status-header">
                        <a class="avatar" href="<?= e($authorId > 0 ? author_url($authorId) : '#') ?>" aria-label="<?= e($authorName) ?>">
                            <?= part('user/avatar', ['user' => $item, 'alt' => $authorName]) ?>
                        </a>
                        <div class="status-author">
                            <?php if ($authorId > 0 && $authorName !== ''): ?>
                                <a href="<?= e(author_url($authorId)) ?>" rel="author"><?= e($authorName) ?></a>
                            <?php endif; ?>
                            <?php if ($createdAt !== ''): ?>
                                <?= part('status/time-link', [
                                    'created_at' => $createdAt,
                                    'content_id' => $contentId,
                                    'open_modal' => false,
                                ]) ?>
                            <?php endif; ?>
                        </div>
                        <?= part('status/manage-actions', ['item' => $item, 'user' => auth(), 'action' => $pageAction]) ?>
                    </div>

                    <?php $bodyHtml = render_status_body($item); ?>
                    <?php if ($bodyHtml !== ''): ?>
                        <div class="status-body"><?= $bodyHtml ?></div>
                    <?php endif; ?>
                    <?= part('status/links', ['item' => $item]) ?>

                    <?= part('status/actions', [
                        'item' => $item,
                        'user' => auth(),
                        'action' => $pageAction,
                        'open_comments_modal' => false,
                    ]) ?>
                    <?= part('status/comments-thread', [
                        'item' => $item,
                        'user' => auth(),
                        'action' => $pageAction,
                        'context' => 'status-' . $contentId,
                    ]) ?>
                </div>
            </article>
        </div>
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
