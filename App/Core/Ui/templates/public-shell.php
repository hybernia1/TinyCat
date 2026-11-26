<?php
declare(strict_types=1);
/** @var bool $public */
/** @var string $title */
/** @var string $actions */
/** @var string $content */
/** @var string $publicHeader */
/** @var string $consent */
/** @var string $publicFooter */
?>
<?= $publicHeader ?>
<main id="main-content" class="section" tabindex="-1">
    <div class="container stack stack-gap-16">
        <?php if (!$public): ?><header class="cluster"><h1 class="m-0"><?= \TinyCat\Core\Ui\Html::e($title) ?></h1><?= $actions ?></header><?php endif; ?>
        <?= $content ?>
    </div>
</main>
<?= $consent ?>
<?= $publicFooter ?>
