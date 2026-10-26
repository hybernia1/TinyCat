<?php
declare(strict_types=1);
/** @var array<string, string> $pageUrls */
/** @var string $query */
/** @var bool $hasQuery */
/** @var array<mixed>|null $searchBlocked */
/** @var array<mixed> $statusItems */
/** @var string $current */
/** @var array<mixed>|null $authUser */
/** @var mixed $field */
/** @var mixed $authModalId */
/** @var mixed $authModalUrl */
/** @var mixed $publicSidebar */
?>
<section class="public-layout">
    <div class="home-feed-section stack stack-gap-16">
        <header class="public-list-header">
            <h1 class="text-2xl m-0"><?= \TinyCat\Support\Html::e($hasQuery ? \TinyCat\Support\Localization::t('public.search_title_query', ['query' => $query]) : \TinyCat\Support\Localization::t('public.search_title')) ?></h1>
        </header>

        <?php if (!$hasQuery): ?>
            <div class="alert alert-info"><?= \TinyCat\Support\Html::et('public.search_min') ?></div>
        <?php elseif ($searchBlocked !== null): ?>
            <?php if (($searchBlocked['code'] ?? '') === 'captcha_required'): ?>
                <form class="card" action="<?= \TinyCat\Support\Html::e($pageUrls['/search']) ?>" method="post">
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <input type="hidden" name="_search_captcha" value="1">
                    <input type="hidden" name="q" value="<?= \TinyCat\Support\Html::e($query) ?>">
                    <div class="card-header">
                        <strong><?= \TinyCat\Support\Html::et('public.search_captcha_title') ?></strong>
                    </div>
                    <div class="card-body stack stack-gap-12">
                        <p class="muted m-0"><?= \TinyCat\Support\Html::et('public.search_captcha_required') ?></p>
                        <?= $field ?>
                    </div>
                    <div class="card-footer justify-end">
                        <a class="btn btn-ghost" href="<?= \TinyCat\Support\Html::e($pageUrls['/login']) ?>" data-modal-open="<?= \TinyCat\Support\Html::e($authModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($authModalUrl) ?>"><?= \TinyCat\Support\Html::icon('login') ?> <span><?= \TinyCat\Support\Html::et('auth.login_title') ?></span></a>
                        <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('confirm') ?> <span><?= \TinyCat\Support\Html::et('common.confirm') ?></span></button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-info"><?= \TinyCat\Support\Html::e((string) ($searchBlocked['message'] ?? \TinyCat\Support\Localization::t('moderation.messages.action_limited'))) ?></div>
            <?php endif; ?>
        <?php elseif ($statusItems === []): ?>
            <div class="alert alert-info"><?= \TinyCat\Support\Html::et('public.search_empty') ?></div>
        <?php else: ?>
            <div class="status-feed">
                <?= \TinyCat\Support\View::part('Status/feed', [
                    'items' => $statusItems,
                    'action' => $current,
                    'user' => $authUser,
                ]) ?>
            </div>
        <?php endif; ?>
    </div>
    <?= $publicSidebar ?>
</section>
