<?php
declare(strict_types=1);
/** @var string $tag */
/** @var array<mixed> $statusItems */
/** @var string $current */
/** @var array<mixed>|null $authUser */
/** @var array<mixed> $feedMore */
/** @var mixed $tagFeedUrl */
/** @var mixed $publicSidebar */
$feedId = 'status-feed-tag-' . \TinyCat\Support\Text::slug($tag);
?>
<section class="public-layout">
    <div class="home-feed-section stack stack-gap-14">
        <header class="public-list-header public-list-header-actions">
            <h1 class="text-2xl m-0"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t('public.tag_feed_title', ['tag' => '#' . $tag])) ?></h1>
            <a class="btn btn-ghost btn-sm" href="<?= \TinyCat\Support\Html::e($tagFeedUrl) ?>" target="_blank" rel="noopener" title="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>" aria-label="<?= \TinyCat\Support\Html::et('public.rss_feed') ?>">
                <?= \TinyCat\Support\Html::icon('rss') ?> <span>RSS</span>
            </a>
        </header>

        <?php if ($statusItems === []): ?>
            <div class="alert alert-info"><?= \TinyCat\Support\Html::et('public.tag_feed_empty') ?></div>
        <?php else: ?>
            <div class="status-feed" id="<?= \TinyCat\Support\Html::e($feedId) ?>" data-status-feed>
                <?= \TinyCat\Support\View::part('Status/feed', [
                    'items' => $statusItems,
                    'action' => $current,
                    'user' => $authUser,
                ]) ?>
            </div>
            <?= \TinyCat\Status\Views::feedMore($feedMore) ?>
        <?php endif; ?>
    </div>
    <?= $publicSidebar ?>
</section>
