<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $csrfToken */
/** @var array<string, string|int|null> $htmlAttributes */
/** @var array<string, string|int|null> $bodyAttributes */
/** @var \TinyCat\Support\Markup $metadata */
/** @var \TinyCat\Support\Markup $content */
/** @var list<string> $styles */
/** @var list<string> $scripts */
/** @var list<array<string, string>> $flashToasts */
/** @var string $analyticsMeasurementId */
/** @var bool $skipLink */
/** @var bool $colorScheme */
/** @var string $robots */
use TinyCat\Support\Html;
?>
<!doctype html>
<html<?php foreach ($htmlAttributes as $name => $value): ?><?php if ($value !== null): ?> <?= Html::e($name) ?>="<?= Html::e($value) ?>"<?php endif; ?><?php endforeach; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($colorScheme): ?><meta name="color-scheme" content="light dark"><?php endif; ?>
    <?php if ($csrfToken !== ''): ?><meta name="csrf-token" content="<?= Html::e($csrfToken) ?>"><?php endif; ?>
    <title><?= Html::e($title) ?></title>
    <?php if ($robots !== ''): ?><meta name="robots" content="<?= Html::e($robots) ?>"><?php endif; ?>
    <?= $metadata->html ?>
    <?php foreach ($styles as $url): ?><link rel="stylesheet" href="<?= Html::e($url) ?>"><?php endforeach; ?>
    <?php foreach ($scripts as $url): ?><script src="<?= Html::e($url) ?>" defer></script><?php endforeach; ?>
    <?php if ($analyticsMeasurementId !== ''): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= \TinyCat\Support\Html::e($analyticsMeasurementId) ?>"></script>
        <script>
            window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
            gtag('consent','default',{
                analytics_storage:'granted',
                ad_storage:'denied',
                ad_user_data:'denied',
                ad_personalization:'denied',
                wait_for_update:500
            });
            gtag('js',new Date());
            gtag('config','<?= \TinyCat\Support\Html::e($analyticsMeasurementId) ?>');
        </script>
    <?php endif; ?>
    <?php if ($flashToasts !== []): ?><template data-tinycat-flashes><?= json_encode($flashToasts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></template><?php endif; ?>
</head>
<body<?php foreach ($bodyAttributes as $name => $value): ?><?php if ($value !== null): ?> <?= Html::e($name) ?>="<?= Html::e($value) ?>"<?php endif; ?><?php endforeach; ?>>
    <?php if ($skipLink): ?><a class="skip-link" href="#main-content"><?= Html::et('common.skip_to_content') ?></a><?php endif; ?>
    <?= $content->html ?>
</body>
</html>
