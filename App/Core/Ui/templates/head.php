<?php
declare(strict_types=1);
/** @var string $appName */
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
/** @var string $siteFaviconUrl */
/** @var array<mixed> $siteIdentity */
/** @var list<array{language: string, url: string}> $languageAlternatives */
/** @var string $theme */
?>
    <?php if ($theme === 'light'): ?>
        <meta name="theme-color" content="<?= \TinyCat\Core\Ui\Html::e((string) $siteIdentity['light_theme_color']) ?>">
    <?php elseif ($theme === 'dark'): ?>
        <meta name="theme-color" content="<?= \TinyCat\Core\Ui\Html::e((string) $siteIdentity['dark_theme_color']) ?>">
    <?php else: ?>
        <meta name="theme-color" content="<?= \TinyCat\Core\Ui\Html::e((string) $siteIdentity['light_theme_color']) ?>" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="<?= \TinyCat\Core\Ui\Html::e((string) $siteIdentity['dark_theme_color']) ?>" media="(prefers-color-scheme: dark)">
    <?php endif; ?>
    <link rel="manifest" href="<?= \TinyCat\Core\Ui\Html::e((string) $siteIdentity['manifest_url']) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta name="description" content="<?= \TinyCat\Core\Ui\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaRobots !== ''): ?>
        <meta name="robots" content="<?= \TinyCat\Core\Ui\Html::e($metaRobots) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= \TinyCat\Core\Ui\Html::e($metaUrl) ?>">
    <?php foreach ($languageAlternatives as $alternative): ?>
        <link rel="alternate" hreflang="<?= \TinyCat\Core\Ui\Html::e($alternative['language']) ?>" href="<?= \TinyCat\Core\Ui\Html::e($alternative['url']) ?>">
    <?php endforeach; ?>
    <?php foreach (['oembed' => 'application/json+oembed', 'oembed_xml' => 'text/xml+oembed'] as $key => $type): ?>
        <?php if (!empty($meta[$key])): ?><link rel="alternate" type="<?= \TinyCat\Core\Ui\Html::e($type) ?>" href="<?= \TinyCat\Core\Ui\Html::e($meta[$key]) ?>" title="<?= \TinyCat\Core\Ui\Html::e($pageTitle) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <link rel="privacy-policy" href="<?= \TinyCat\Core\Ui\Html::e($privacyUrl) ?>">
    <?php if ($metaRss !== ''): ?>
        <link rel="alternate" type="application/rss+xml" title="<?= \TinyCat\Core\Ui\Html::e($pageTitle) ?>" href="<?= \TinyCat\Core\Ui\Html::e($metaRssUrl) ?>">
    <?php endif; ?>
    <?php if ($metaPrev !== ''): ?>
        <link rel="prev" href="<?= \TinyCat\Core\Ui\Html::e($metaPrevUrl) ?>">
    <?php endif; ?>
    <?php if ($metaNext !== ''): ?>
        <link rel="next" href="<?= \TinyCat\Core\Ui\Html::e($metaNextUrl) ?>">
    <?php endif; ?>
    <meta property="og:site_name" content="<?= \TinyCat\Core\Ui\Html::e($appName) ?>">
    <meta property="og:title" content="<?= \TinyCat\Core\Ui\Html::e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta property="og:description" content="<?= \TinyCat\Core\Ui\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?= \TinyCat\Core\Ui\Html::e($metaType) ?>">
    <meta property="og:url" content="<?= \TinyCat\Core\Ui\Html::e($metaUrl) ?>">
    <meta property="og:locale" content="<?= \TinyCat\Core\Ui\Html::e($metaLocale) ?>">
    <?php if ($metaType === 'article' && !empty($meta['published_time'])): ?>
        <meta property="article:published_time" content="<?= \TinyCat\Core\Ui\Html::e(\TinyCat\Support\Dates::dateTime((string) $meta['published_time'], DATE_ATOM)) ?>">
    <?php endif; ?>
    <?php if ($metaType === 'article' && !empty($meta['author'])): ?>
        <meta property="article:author" content="<?= \TinyCat\Core\Ui\Html::e((string) $meta['author']) ?>">
    <?php endif; ?>
    <?php if ($metaType === 'article'): ?>
        <?php foreach ((array) ($meta['tags'] ?? []) as $tag): ?>
            <meta property="article:tag" content="<?= \TinyCat\Core\Ui\Html::e((string) $tag) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta property="og:image" content="<?= \TinyCat\Core\Ui\Html::e($metaImage) ?>">
        <?php if ($metaImageType !== ''): ?><meta property="og:image:type" content="<?= \TinyCat\Core\Ui\Html::e($metaImageType) ?>"><?php endif; ?>
        <?php if ($metaImageWidth > 0): ?><meta property="og:image:width" content="<?= \TinyCat\Core\Ui\Html::e($metaImageWidth) ?>"><?php endif; ?>
        <?php if ($metaImageHeight > 0): ?><meta property="og:image:height" content="<?= \TinyCat\Core\Ui\Html::e($metaImageHeight) ?>"><?php endif; ?>
        <meta property="og:image:alt" content="<?= \TinyCat\Core\Ui\Html::e($metaImageAlt) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?= $metaImage !== '' ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= \TinyCat\Core\Ui\Html::e($metaTitle) ?>">
    <?php if ($metaDescription !== ''): ?>
        <meta name="twitter:description" content="<?= \TinyCat\Core\Ui\Html::e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($metaImage !== ''): ?>
        <meta name="twitter:image" content="<?= \TinyCat\Core\Ui\Html::e($metaImage) ?>">
        <meta name="twitter:image:alt" content="<?= \TinyCat\Core\Ui\Html::e($metaImageAlt) ?>">
    <?php endif; ?>
    <?php if ($siteFaviconUrl !== ''): ?>
        <link rel="icon" type="image/webp" href="<?= \TinyCat\Core\Ui\Html::e($siteFaviconUrl) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['favicon_url'] !== ''): ?>
        <link rel="icon" type="image/png" sizes="32x32" href="<?= \TinyCat\Core\Ui\Html::e((string) $siteIdentity['favicon_url']) ?>">
    <?php endif; ?>
    <?php if ((string) $siteIdentity['apple_touch_icon_url'] !== ''): ?>
        <link rel="apple-touch-icon" sizes="180x180" href="<?= \TinyCat\Core\Ui\Html::e((string) $siteIdentity['apple_touch_icon_url']) ?>">
    <?php endif; ?>
    <?php if (is_string($metaJsonLdJson) && $metaJsonLdJson !== ''): ?>
        <script type="application/ld+json"><?= $metaJsonLdJson ?></script>
    <?php endif; ?>
