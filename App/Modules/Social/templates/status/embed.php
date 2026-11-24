<?php
declare(strict_types=1);
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
/** @var string $author */
/** @var string $url */
/** @var string $siteName */
/** @var \TinyCat\Support\Markup $card */
/** @var list<string> $cardStyles */
/** @var string $styleUrl */
/** @var string $scriptUrl */
/** @var bool $share */
/** @var int $maxHeight */
/** @var string $code */
?>
<!doctype html>
<html lang="<?= Html::e(Localization::locale()) ?>" data-embed-maxheight="<?= $maxHeight ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light dark">
    <title><?= Html::e($author . ' · ' . $siteName) ?></title>
    <link rel="canonical" href="<?= Html::e($url) ?>">
    <?php foreach ($cardStyles as $cardStyle): ?><link rel="stylesheet" href="<?= Html::e($cardStyle) ?>"><?php endforeach; ?>
    <link rel="stylesheet" href="<?= Html::e($styleUrl) ?>">
    <script defer src="<?= Html::e($scriptUrl) ?>"></script>
</head>
<body<?= $share ? ' class="share-page"' : '' ?>>
<main>
    <?php if ($share): ?>
        <header class="share-heading"><h1><?= Html::et('account.status_embed') ?></h1></header>
    <?php endif; ?>
    <?= $card->html ?>
    <?php if ($share): ?>
        <section class="share-code"><label for="embed-code"><?= Html::et('account.status_embed_code') ?></label><textarea id="embed-code" readonly rows="7" spellcheck="false"><?= Html::e($code) ?></textarea><a href="<?= Html::e($url) ?>"><?= Html::et('account.status_embed_open') ?></a></section>
    <?php endif; ?>
</main>
</body>
</html>
