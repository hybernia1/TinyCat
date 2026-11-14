<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $shell */
/** @var array<mixed>|null $authUser */
/** @var string $theme */
/** @var list<string> $styles */
/** @var list<string> $scripts */
/** @var string $csrf */
/** @var string $iconSprite */
/** @var list<array<string, string>> $flashToasts */
/** @var string $dateFormat */
/** @var bool $dateRelative */
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
?>
<!doctype html>
<html lang="<?= Html::e(Localization::locale()) ?>"<?= $theme !== 'system' ? ' data-theme="' . Html::e($theme) . '"' : '' ?> data-date-format="<?= Html::e($dateFormat) ?>" data-date-relative="<?= $dateRelative ? 'true' : 'false' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="<?= Html::e($csrf) ?>">
    <meta name="robots" content="noindex,nofollow">
    <title><?= Html::e($title) ?></title>
    <?php foreach ($styles as $url): ?><link rel="stylesheet" href="<?= Html::e($url) ?>"><?php endforeach; ?>
    <?php foreach ($scripts as $url): ?><script src="<?= Html::e($url) ?>" defer></script><?php endforeach; ?>
    <?php if ($flashToasts !== []): ?><template data-tinycat-flashes><?= json_encode($flashToasts, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></template><?php endif; ?>
</head>
<body class="admin-shell-page" data-application-version="<?= Html::e(\TinyCat\Support\Version::CURRENT) ?>" data-current-user-id="<?= (int) ($authUser['id'] ?? 0) ?>" data-icon-sprite="<?= Html::e($iconSprite) ?>">
    <a class="skip-link" href="#main-content"><?= Html::et('common.skip_to_content') ?></a>
    <?= $shell ?>
</body>
</html>
