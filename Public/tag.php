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
$statusItems = public_status_items_by_tag($tag, $statusLimit);

layout('layout', [
    'title' => t('public.tag_feed_title', ['tag' => '#' . $tag]),
    'current' => $current,
    'meta' => [
        'description' => t('public.tag_meta', ['tag' => '#' . $tag]),
        'url' => $current,
        'image' => site_meta_image_url(),
    ],
], static function () use ($tag, $statusItems, $statusLimit, $current): void {
    $feedId = 'status-feed-tag-' . slug($tag);
    ?>
    <section class="public-layout">
        <main class="home-feed-section stack stack-gap-14">
            <header class="public-list-header">
                <h1 class="text-2xl m-0"><?= e(t('public.tag_feed_title', ['tag' => '#' . $tag])) ?></h1>
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
            <?php endif; ?>
        </main>
        <?= public_sidebar($tag) ?>
    </section>
    <?php
});
