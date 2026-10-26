<?php
declare(strict_types=1);
/** @var array<mixed>|null $authUser */
/** @var string $feed */
/** @var array<mixed> $editor */
/** @var mixed $userIsMuted */
/** @var mixed $userMutedUntil */
/** @var mixed $publicHomeFeedData */
/** @var mixed $publicSidebar */
?>
<section class="public-layout">
    <div class="home-feed-app stack">
        <?php if ($authUser !== null && $userIsMuted): ?>
            <div class="alert alert-warning">
                <?= \TinyCat\Support\Html::icon('lock') ?> <span><?= \TinyCat\Support\Html::et('moderation.messages.account_muted', ['until' => \TinyCat\Support\Dates::displayDateTime($userMutedUntil)]) ?></span>
            </div>
        <?php elseif ($authUser !== null): ?>
            <?= \TinyCat\Status\Views::composer([
                'action' => $feed === 'following' ? '/?feed=following' : '/',
                'user' => $authUser,
                'editor' => $editor,
            ]) ?>
        <?php endif; ?>
        <div class="home-feed-section stack">
            <?= \TinyCat\Status\Views::homeFeed($publicHomeFeedData) ?>
        </div>
    </div>
    <?= $publicSidebar ?>
</section>
