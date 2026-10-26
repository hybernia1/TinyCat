<?php
declare(strict_types=1);
/** @var array<mixed> $item */
/** @var array<mixed> $comments */
/** @var array<mixed>|null $authUser */
/** @var string $current */
/** @var bool $compact */
/** @var string $pageAction */
/** @var mixed $publicSidebar */
$authorId = (int) ($item['author_id'] ?? 0);
$authorName = trim((string) ($item['author_name'] ?? ''));
$publishedAt = (string) ($item['published_at'] ?? '');
$contentId = (int) ($item['id'] ?? 0);
?>
<section class="public-layout">
    <div class="home-feed-section stack stack-gap-14">
        <h1 class="sr-only"><?= \TinyCat\Support\Html::et('public.status_title') ?></h1>
        <?= \TinyCat\Status\Views::card(['item' => $item, 'user' => $authUser, 'action' => $pageAction, 'open_modal' => false]) ?>
        <?= \TinyCat\Status\Views::commentsThread(['item' => $item, 'comments' => $comments, 'user' => $authUser, 'action' => $pageAction, 'context' => 'status-' . $contentId]) ?>
    </div>
    <?php if (!$compact): ?><?= $publicSidebar ?><?php endif; ?>
</section>
