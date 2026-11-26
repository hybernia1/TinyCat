<?php
declare(strict_types=1);
/** @var int $status */
/** @var string $title */
/** @var string $intro */
/** @var string $homeUrl */
/** @var string $homeLabel */
/** @var string $searchUrl */
/** @var string $searchLabel */
?>
<section class="stack stack-gap-16" aria-labelledby="error-title">
    <p class="text-2xl text-primary m-0" aria-hidden="true"><?= $status ?></p>
    <div class="stack stack-gap-8">
        <h1 class="text-2xl m-0" id="error-title"><?= \TinyCat\Core\Ui\Html::icon('alert') ?> <?= \TinyCat\Core\Ui\Html::e($title) ?></h1>
        <p class="text-muted m-0"><?= \TinyCat\Core\Ui\Html::e($intro) ?></p>
    </div>
    <div class="btn-group">
        <a class="btn btn-primary" href="<?= \TinyCat\Core\Ui\Html::e($homeUrl) ?>"><?= \TinyCat\Core\Ui\Html::icon('home') ?> <span><?= \TinyCat\Core\Ui\Html::e($homeLabel) ?></span></a>
        <?php if ($searchUrl !== ''): ?>
        <a class="btn btn-secondary" href="<?= \TinyCat\Core\Ui\Html::e($searchUrl) ?>"><?= \TinyCat\Core\Ui\Html::icon('search') ?> <span><?= \TinyCat\Core\Ui\Html::e($searchLabel) ?></span></a>
        <?php endif; ?>
    </div>
</section>
