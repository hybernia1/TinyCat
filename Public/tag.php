<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$tag = \TinyCat\Status\Tags::status_tag_normalize((string) \TinyCat\Http\Web::get('tag', ''));

if ($tag === '') {
    http_response_code(404);
    $notFoundTitle = \TinyCat\Support\Localization::t('public.tag_not_found');
    $notFoundCurrent = '/tag';
    require \TinyCat\Support\Paths::publicPath('not-found.php');
    return;
}

$current = \TinyCat\Status\Tags::tag_url($tag);

if (\TinyCat\Http\Request::current()->method() === 'GET' && \TinyCat\Http\Request::current()->path() !== $current) {
    \TinyCat\Http\Web::redirect($current, 301);
}

if (\TinyCat\Http\Request::current()->method() === 'GET' && (int) \TinyCat\Http\Web::get('page', 0) > 1) {
    \TinyCat\Http\Web::redirect($current, 301);
}

$statusLimit = \TinyCat\Status\Feed::public_status_initial_page_limit();
$nextStatusLimit = \TinyCat\Status\Feed::public_status_page_limit();
$statusItems = \TinyCat\Status\Feed::public_status_items_by_tag($tag, $statusLimit);
$indexableItems = $statusLimit >= 2 ? $statusItems : \TinyCat\Status\Feed::public_status_items_by_tag($tag, 2);
$authUser = \TinyCat\Users\Authentication::auth();
$statusItems = \TinyCat\Status\ViewData::status_prepare_items_view($statusItems, $authUser);
$feedMore = \TinyCat\Status\ViewData::status_feed_more_view_data(
    'status-feed-tag-' . \TinyCat\Support\Text::slug($tag),
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
    '@id' => \TinyCat\Site\Presentation::absolute_url($pageUrl),
    'url' => \TinyCat\Site\Presentation::absolute_url($pageUrl),
    'name' => \TinyCat\Support\Localization::t('public.tag_feed_title', ['tag' => '#' . $tag]),
    'isPartOf' => ['@id' => \TinyCat\Site\Presentation::absolute_url('/')],
    'mainEntity' => [
        '@type' => 'ItemList',
        'itemListElement' => array_values(array_map(static function (array $item, int $index): array {
            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => \TinyCat\Site\Presentation::absolute_url(\TinyCat\Status\Posts::status_url((int) ($item['id'] ?? 0))),
            ];
        }, $statusItems, array_keys($statusItems))),
    ],
];

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('public.tag_feed_title', ['tag' => '#' . $tag]),
    'current' => $current,
    'meta' => [
        'description' => \TinyCat\Support\Localization::t('public.tag_meta', ['tag' => '#' . $tag]),
        'url' => $pageUrl,
        'image' => \TinyCat\Site\Presentation::site_meta_image_url(),
        'rss' => \TinyCat\Status\Tags::tag_feed_url($tag),
        'robots' => count($indexableItems) >= 2 ? '' : 'noindex,follow',
        'jsonld' => $tagStructuredData,
    ],
], static function () use ($tag, $statusItems, $current, $authUser, $feedMore): void {
    $feedId = 'status-feed-tag-' . \TinyCat\Support\Text::slug($tag);
    ?>
    <section class="public-layout">
        <div class="home-feed-section stack stack-gap-14">
            <header class="public-list-header public-list-header-actions">
                <h1 class="text-2xl m-0"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t('public.tag_feed_title', ['tag' => '#' . $tag])) ?></h1>
                <a class="btn btn-ghost btn-sm" href="<?= \TinyCat\Support\Html::e(\TinyCat\Status\Tags::tag_feed_url($tag)) ?>" target="_blank" rel="noopener" title="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>" aria-label="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>">
                    <?= \TinyCat\Support\Html::icon('rss') ?> <span>RSS</span>
                </a>
            </header>

            <?php if ($statusItems === []): ?>
                <div class="alert alert-info"><?= \TinyCat\Support\Html::et('public.tag_feed_empty') ?></div>
            <?php else: ?>
                <div class="status-feed" id="<?= \TinyCat\Support\Html::e($feedId) ?>" data-status-feed>
                    <?= \TinyCat\Support\View::part('status/feed', [
                        'items' => $statusItems,
                        'action' => $current,
                        'user' => $authUser,
                    ]) ?>
                </div>
                <?= \TinyCat\Status\Views::feedMore($feedMore) ?>
            <?php endif; ?>
        </div>
        <?= \TinyCat\Site\Presentation::public_sidebar($tag) ?>
    </section>
    <?php
});
