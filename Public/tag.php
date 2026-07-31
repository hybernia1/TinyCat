<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$tag = status_tag_normalize((string) get('tag', ''));

if ($tag === '') {
    http_response_code(404);
    layout('layout', [
        'title' => t('public.tag_not_found'),
        'current' => '/tag',
        'meta' => [
            'description' => t('public.tag_not_found'),
            'url' => '/tag',
            'robots' => 'noindex,follow',
        ],
    ], static function (): void {
        ?>
        <div class="alert alert-info"><?= et('public.tag_not_found') ?></div>
        <?php
    });
    return;
}

$current = tag_url($tag);

if (method() === 'GET' && route_path() !== $current) {
    redirect($current, 301);
}

$statusLimit = public_status_page_limit();
$pagination = pagination_meta(public_status_count_by_tag($tag), (int) get('page', 1), $statusLimit);
$page = (int) ($pagination['page'] ?? 1);
$pageUrl = $current . ($page > 1 ? '?page=' . $page : '');
$statusItems = public_status_items_by_tag_offset($tag, $statusLimit, (int) ($pagination['offset'] ?? 0));
$prevUrl = ($pagination['has_prev'] ?? false) ? $current . '?page=' . ((int) $page - 1) : '';
$nextUrl = ($pagination['has_next'] ?? false) ? $current . '?page=' . ((int) $page + 1) : '';
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
    'meta' => [
        'description' => t('public.tag_meta', ['tag' => '#' . $tag]),
        'url' => $pageUrl,
        'image' => site_meta_image_url(),
        'rss' => tag_feed_url($tag),
        'prev' => $prevUrl,
        'next' => $nextUrl,
        'jsonld' => $tagStructuredData,
    ],
], static function () use ($tag, $statusItems, $statusLimit, $current, $pagination): void {
    $feedId = 'status-feed-tag-' . slug($tag);
    ?>
    <section class="public-layout">
        <main class="home-feed-section stack stack-gap-14">
            <header class="public-list-header">
                <h1 class="text-2xl m-0"><?= e(t('public.tag_feed_title', ['tag' => '#' . $tag])) ?></h1>
                <a class="btn btn-ghost btn-sm" href="<?= e(tag_feed_url($tag)) ?>" title="RSS feed" aria-label="RSS feed">
                    <?= icon('rss') ?> <span>RSS</span>
                </a>
            </header>

            <?php if ($statusItems === []): ?>
                <div class="alert alert-info"><?= et('public.tag_feed_empty') ?></div>
            <?php else: ?>
                <div class="status-feed" id="<?= e($feedId) ?>" data-status-feed>
                    <?php foreach ($statusItems as $item): ?>
                        <?= status_card($item, $current) ?>
                    <?php endforeach; ?>
                </div>
                <?= status_feed_more_control(
                    $feedId,
                    'tag',
                    count($statusItems),
                    $statusLimit,
                    ['tag' => $tag] + status_feed_cursor_params($statusItems)
                ) ?>
                <?= pagination($pagination, $current) ?>
            <?php endif; ?>
        </main>
        <?= public_sidebar($tag) ?>
    </section>
    <?php
});
