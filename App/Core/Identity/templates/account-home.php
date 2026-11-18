<?php
declare(strict_types=1);
/** @var array<mixed> $user */
/** @var string $accountAction */
/** @var string $logoutUrl */
use TinyCat\Support\Html;
?>
<section class="max-w-auth-sm mx-auto">
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= Html::icon('user') ?> <?= Html::et('account.title') ?></h1>
        </div>
        <div class="card-body stack">
            <p class="m-0"><?= Html::e('@' . (string) $user['username']) ?></p>
            <div class="cluster gap-2">
                <?= $accountAction ?>
                <a class="btn btn-secondary" href="<?= Html::e($logoutUrl) ?>"><?= Html::icon('logout') ?> <?= Html::et('common.logout') ?></a>
            </div>
        </div>
    </article>
</section>
