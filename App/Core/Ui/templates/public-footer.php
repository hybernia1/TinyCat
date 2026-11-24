<?php
declare(strict_types=1);
/** @var string $privacyUrl */
use TinyCat\Support\Html;
?>
<footer class="container section text-sm text-muted desktop-only">
    <a class="cluster gap-2 text-muted" href="<?= Html::e($privacyUrl) ?>" rel="privacy-policy"><?= Html::icon('shield') ?> <span><?= Html::et('privacy.title') ?></span></a>
</footer>
