<?php
declare(strict_types=1);
/** @var bool $analyticsConfigured */
/** @var string $analyticsConsent */
/** @var string $appName */
/** @var string $csrfToken */
/** @var array<mixed> $flashToasts */
/** @var string $googleMeasurementId */
/** @var array<mixed> $meta */
/** @var string $metaDescription */
/** @var string $metaImage */
/** @var string $metaImageAlt */
/** @var int $metaImageHeight */
/** @var string $metaImageType */
/** @var int $metaImageWidth */
/** @var string|false $metaJsonLdJson */
/** @var string $metaLocale */
/** @var string $metaNext */
/** @var string $metaNextUrl */
/** @var string $metaPrev */
/** @var string $metaPrevUrl */
/** @var string $metaRobots */
/** @var string $metaRss */
/** @var string $metaRssUrl */
/** @var string $metaTitle */
/** @var string $metaType */
/** @var string $metaUrl */
/** @var string $privacyUrl */
/** @var string $pageTitle */
/** @var list<string> $scriptUrls */
/** @var string $siteFaviconUrl */
/** @var array<mixed> $siteIdentity */
/** @var list<string> $styleUrls */
/** @var list<array{language: string, url: string}> $languageAlternatives */
/** @var string $interfacePrefix */
/** @var string $interfaceLocales */
/** @var string $theme */
/** @var string $themeAttribute */
/** @var string $dateFormat */
/** @var bool $dateRelative */
?>
<!doctype html>
<html lang="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::locale()) ?>"<?= $themeAttribute ?> data-interface-prefix="<?= \TinyCat\Support\Html::e($interfacePrefix) ?>" data-interface-locales="<?= \TinyCat\Support\Html::e($interfaceLocales) ?>" data-date-format="<?= \TinyCat\Support\Html::e($dateFormat) ?>" data-date-relative="<?= $dateRelative ? 'true' : 'false' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <?php if ($theme === 'light'): ?>
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['light_theme_color']) ?>">
    <?php elseif ($theme === 'dark'): ?>
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['dark_theme_color']) ?>">
    <?php else: ?>
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['light_theme_color']) ?>" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="<?= \TinyCat\Support\Html::e((string) $siteIdentity['dark_theme_color']) ?>" media="(prefers-color-scheme: dark)">
    <?php endif; ?>
    <link rel="manifest" href="<?= \TinyCat\Support\Html::e((string) $siteIdentity['manifest_url']) ?>">
    <?php if ($csrfToken !== ''): ?>
        <meta name="csrf-token" content="<?= \TinyCat\Support\Html::e($csrfToken) ?>">
    <?php endif; ?>
    <title><?= \TinyCat\Support\Html::e($pageTitle) ?></title>
    <?php if ($metaDescription !== ''): ?>
        <meta name="description" content="<?= \TinyCat\Support\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaRobots !== ''): ?>
        <meta name="robots" content="<?= \TinyCat\Support\Html::e($metaRobots) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= \TinyCat\Support\Html::e($metaUrl) ?>">
    <?php foreach ($languageAlternatives as $alternative): ?>
        <link rel="alternate" hreflang="<?= \TinyCat\Support\Html::e($alternative['language']) ?>" href="<?= \TinyCat\Support\Html::e($alternative['url']) ?>">
    <?php endforeach; ?>
    <link rel="privacy-policy" href="<?= \TinyCat\Support\Html::e($privacyUrl) ?>">
    <?php if ($metaRss !== ''): ?>
        <link rel="alternate" type="application/rss+xml" title="<?= \TinyCat\Support\Html::e($pageTitle) ?>" href="<?= \TinyCat\Support\Html::e($metaRssUrl) ?>">
    <?php endif; ?>
    <?php if ($metaPrev !== ''): ?>
        <link rel="prev" href="<?= \TinyCat\Support\Html::e($metaPrevUrl) ?>">
    <?php endif; ?>
    <?php if ($metaNext !== ''): ?>
        <link rel="next" href="<?= \TinyCat\Support\Html::e($metaNextUrl) ?>">
    <?php endif; ?>
    <meta property="og:site_name" content="<?= \TinyCat\Support\Html::e($appName) ?>">
    <meta property="og:title" content="<?= \TinyCat\Support\Html::e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta property="og:description" content="<?= \TinyCat\Support\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?= \TinyCat\Support\Html::e($metaType) ?>">
    <meta property="og:url" content="<?= \TinyCat\Support\Html::e($metaUrl) ?>">
    <meta property="og:locale" content="<?= \TinyCat\Support\Html::e($metaLocale) ?>">
    <?php if ($metaType === 'article' && !empty($meta['published_time'])): ?>
        <meta property="article:published_time" content="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::dateIso((string) $meta['published_time'])) ?>">
    <?php endif; ?>
    <?php if ($metaType === 'article' && !empty($meta['author'])): ?>
        <meta property="article:author" content="<?= \TinyCat\Support\Html::e((string) $meta['author']) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta property="og:image" content="<?= \TinyCat\Support\Html::e($metaImage) ?>">
        <?php if ($metaImageType !== ''): ?><meta property="og:image:type" content="<?= \TinyCat\Support\Html::e($metaImageType) ?>"><?php endif; ?>
        <?php if ($metaImageWidth > 0): ?><meta property="og:image:width" content="<?= \TinyCat\Support\Html::e($metaImageWidth) ?>"><?php endif; ?>
        <?php if ($metaImageHeight > 0): ?><meta property="og:image:height" content="<?= \TinyCat\Support\Html::e($metaImageHeight) ?>"><?php endif; ?>
        <meta property="og:image:alt" content="<?= \TinyCat\Support\Html::e($metaImageAlt) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $metaImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= \TinyCat\Support\Html::e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta name="twitter:description" content="<?= \TinyCat\Support\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta name="twitter:image" content="<?= \TinyCat\Support\Html::e($metaImage) ?>">
        <meta name="twitter:image:alt" content="<?= \TinyCat\Support\Html::e($metaImageAlt) ?>">
    <?php endif; ?>
    <?php if ($siteFaviconUrl !== ''): ?>
        <link rel="icon" type="image/webp" href="<?= \TinyCat\Support\Html::e($siteFaviconUrl) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['favicon_url'] !== ''): ?>
        <link rel="icon" type="image/png" sizes="32x32" href="<?= \TinyCat\Support\Html::e((string) $siteIdentity['favicon_url']) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['apple_touch_icon_url'] !== ''): ?>
        <link rel="apple-touch-icon" sizes="180x180" href="<?= \TinyCat\Support\Html::e((string) $siteIdentity['apple_touch_icon_url']) ?>">
    <?php endif; ?>
    <?php if (is_string($metaJsonLdJson) && $metaJsonLdJson !== ''): ?>
        <script type="application/ld+json"><?= $metaJsonLdJson ?></script>
    <?php endif; ?>
    <?php foreach ($styleUrls as $styleUrl): ?>
        <link rel="stylesheet" href="<?= \TinyCat\Support\Html::e($styleUrl) ?>">
    <?php endforeach; ?>
    <?php foreach ($scriptUrls as $scriptUrl): ?>
        <script src="<?= \TinyCat\Support\Html::e($scriptUrl) ?>" defer></script>
    <?php endforeach; ?>
    <?php if ($analyticsConfigured && $analyticsConsent === 'granted'): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= \TinyCat\Support\Html::e($googleMeasurementId) ?>"></script>
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
            gtag('config','<?= \TinyCat\Support\Html::e($googleMeasurementId) ?>');
        </script>
    <?php endif; ?>
    <?= \TinyCat\Support\View::part('Site/flashes', ['items' => $flashToasts]) ?>
</head>
