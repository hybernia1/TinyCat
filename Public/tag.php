<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$tag = status_tag_normalize((string) get('tag', ''));

if ($tag === '') {
    http_response_code(404);
    $notFoundTitle = t('public.tag_not_found');
    $notFoundCurrent = '/tag';
    require public_path('not-found.php');
    return;
}

$current = tag_url($tag);

if (method() === 'GET' && route_path() !== $current) {
    redirect($current, 301);
}

if (method() === 'GET' && (int) get('page', 0) > 1) {
    redirect($current, 301);
}

$statusLimit = public_status_initial_page_limit();
$nextStatusLimit = public_status_page_limit();
$statusItems = public_status_items_by_tag($tag, $statusLimit);
$indexableItems = $statusLimit >= 2 ? $statusItems : public_status_items_by_tag($tag, 2);
$authUser = auth();
$statusItems = status_prepare_items_view($statusItems, $authUser);
$feedMore = status_feed_more_view_data(
    'status-feed-tag-' . slug($tag),
    'tag',
    $statusItems,
    $statusLimit,
    ['tag' => $tag],
    $nextStatusLimit
);
$pageUrl = $current;
$tagStructuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    '@id' => absolute_url($pageUrl),
    'url' => absolute_url($pageUrl),
    'name' => t('public.tag_feed_title', ['tag' => '#' . $tag]),
    'isPartOf' => ['@id' => absolute_url('/')],
    'mainEntity' => [
        '@type' => 'ItemList',
        'itemListElement' => array_values(array_map(static function (array $item, int $index): array {
            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => absolute_url(status_url((int) ($item['id'] ?? 0))),
            ];
        }, $statusItems, array_keys($statusItems))),
    ],
];

layout('layout', [
    'title' => t('public.tag_feed_title', ['tag' => '#' . $tag]),
    'current' => $current,
    'style_groups' => ['feed', 'avatar'],
    'meta' => [
        'description' => t('public.tag_meta', ['tag' => '#' . $tag]),
        'url' => $pageUrl,
        'image' => site_meta_image_url(),
        'rss' => tag_feed_url($tag),
        'robots' => count($indexableItems) >= 2 ? '' : 'noindex,follow',
        'jsonld' => $tagStructuredData,
    ],
], static function () use ($tag, $statusItems, $current, $authUser, $feedMore): void {
    $feedId = 'status-feed-tag-' . slug($tag);
    ?>
    <section class="public-layout">
        <div class="home-feed-section stack stack-gap-14">
            <header class="public-list-header public-list-header-actions">
                <h1 class="text-2xl m-0"><?= e(t('public.tag_feed_title', ['tag' => '#' . $tag])) ?></h1>
                <a class="btn btn-ghost btn-sm" href="<?= e(tag_feed_url($tag)) ?>" target="_blank" rel="noopener" title="<?= et('public.rss_feed') ?>" aria-label="<?= et('public.rss_feed') ?>">
                    <?= icon('rss') ?> <span>RSS</span>
                </a>
            </header>

            <?php if ($statusItems === []): ?>
                <div class="alert alert-info"><?= et('public.tag_feed_empty') ?></div>
            <?php else: ?>
                <div class="status-feed" id="<?= e($feedId) ?>" data-status-feed>
                    <?= part('status/feed', [
                        'items' => $statusItems,
                        'action' => $current,
                        'user' => $authUser,
                    ]) ?>
                </div>
                <?= part('status/feed-more', $feedMore) ?>
            <?php endif; ?>
        </div>
        <?= public_sidebar($tag) ?>
    </section>
    <?php
});
