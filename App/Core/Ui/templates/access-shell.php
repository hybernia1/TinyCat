<?php
declare(strict_types=1);
/** @var string $appName */
/** @var string $homeUrl */
/** @var string $logoUrl */
/** @var string $privacyUrl */
/** @var string $content */
use TinyCat\Core\Ui\Html;
?>
<header class="container section">
    <a class="cluster gap-2" href="<?= Html::e($homeUrl) ?>">
        <?php if ($logoUrl !== ''): ?><img class="brand-logo" src="<?= Html::e($logoUrl) ?>" alt=""><?php endif; ?>
        <span><?= Html::e($appName) ?></span>
    </a>
</header>
<main id="main-content" class="section" tabindex="-1">
    <div class="container stack stack-gap-16"><?= $content ?></div>
</main>
<footer class="container section text-sm text-muted">
    <a href="<?= Html::e($privacyUrl) ?>" rel="privacy-policy"><?= Html::et('privacy.title') ?></a>
</footer>
