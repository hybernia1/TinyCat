<?php
declare(strict_types=1);
/** @var array<mixed>|null $authUser */
/** @var string $applicationVersion */
/** @var string $bodyClasses */
/** @var string $privacyUrl */
/** @var bool $hasPublicSidebar */
/** @var string $head */
/** @var string $shell */
/** @var string $iconSpriteUrl */
?>
<?= $head ?>
<body<?= $bodyClasses !== '' ? ' class="' . \TinyCat\Support\Html::e($bodyClasses) . '"' : '' ?> data-application-version="<?= \TinyCat\Support\Html::e($applicationVersion) ?>" data-current-user-id="<?= \TinyCat\Support\Html::e((int) ($authUser['id'] ?? 0)) ?>" data-icon-sprite="<?= \TinyCat\Support\Html::e($iconSpriteUrl) ?>">
    <a class="skip-link" href="#main-content"><?= \TinyCat\Support\Html::et('common.skip_to_content') ?></a>
    <?= $shell ?>
    <?php if (!$hasPublicSidebar): ?>
    <footer class="container section text-sm text-muted">
        <a class="cluster gap-2 text-muted" href="<?= \TinyCat\Support\Html::e($privacyUrl) ?>" rel="privacy-policy"><?= \TinyCat\Support\Html::icon('shield') ?> <span><?= \TinyCat\Support\Html::et('privacy.title') ?></span></a>
    </footer>
    <?php endif; ?>
</body>
</html>
