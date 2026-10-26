<?php
declare(strict_types=1);
/** @var array<mixed> $item */
/** @var array<mixed> $comments */
/** @var array<mixed>|null $authUser */
/** @var string $current */
/** @var bool $compact */
/** @var string $pageAction */
/** @var mixed $publicSidebar */
$contentId = (int) ($item['id'] ?? 0);
?>
<section class="<?= $compact ? 'stack' : 'public-layout' ?>" aria-labelledby="status-detail-title">
    <div class="home-feed-section">
        <h1 class="sr-only" id="status-detail-title"><?= \TinyCat\Support\Html::et('public.status_title') ?></h1>
        <div class="stack stack-gap-14">
            <?= \TinyCat\Status\Views::card(['item' => $item, 'user' => $authUser, 'action' => $pageAction, 'open_modal' => false]) ?>
            <?= \TinyCat\Status\Views::commentsThread(['item' => $item, 'comments' => $comments, 'user' => $authUser, 'action' => $pageAction, 'context' => 'status-' . $contentId]) ?>
        </div>
    </div>
    <?php if (!$compact): ?><?= $publicSidebar ?><?php endif; ?>
</section>
