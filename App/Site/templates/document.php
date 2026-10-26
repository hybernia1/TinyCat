<?php
declare(strict_types=1);
/** @var array<mixed>|null $authUser */
/** @var string $bodyClasses */
/** @var string $head */
/** @var string $shell */
/** @var string $iconSpriteUrl */
?>
<?= $head ?>
<body<?= $bodyClasses !== '' ? ' class="' . \TinyCat\Support\Html::e($bodyClasses) . '"' : '' ?> data-current-user-id="<?= \TinyCat\Support\Html::e((int) ($authUser['id'] ?? 0)) ?>" data-icon-sprite="<?= \TinyCat\Support\Html::e($iconSpriteUrl) ?>">
    <a class="skip-link" href="#main-content"><?= \TinyCat\Support\Html::et('common.skip_to_content') ?></a>
    <?= $shell ?>
</body>
</html>
