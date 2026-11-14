<?php
declare(strict_types=1);
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
/** @var string $author */
/** @var string $authorUrl */
/** @var string $avatarUrl */
/** @var string $initial */
/** @var list<array<string, mixed>> $media */
/** @var string $url */
/** @var string $siteName */
/** @var string $siteUrl */
/** @var list<array<string, string>> $segments */
/** @var string $imageUrl */
/** @var string $imageAlt */
/** @var int $imageWidth */
/** @var int $imageHeight */
/** @var string $publishedAt */
/** @var string $publishedLabel */
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
    <link rel="stylesheet" href="<?= Html::e($styleUrl) ?>">
    <script defer src="<?= Html::e($scriptUrl) ?>"></script>
</head>
<body<?= $share ? ' class="share-page"' : '' ?>>
<main>
    <?php if ($share): ?>
        <header class="share-heading"><h1><?= Html::et('account.status_embed') ?></h1><p><?= Html::et('account.status_embed_help') ?></p></header>
    <?php endif; ?>
    <article class="embed-card">
        <header class="embed-author"><a class="embed-author-link" href="<?= Html::e($authorUrl) ?>" target="_blank" rel="noopener noreferrer"><?php if ($avatarUrl !== ''): ?><img class="embed-avatar" src="<?= Html::e($avatarUrl) ?>" alt="" width="40" height="40"><?php else: ?><span class="embed-avatar embed-avatar-initial" aria-hidden="true"><?= Html::e($initial) ?></span><?php endif; ?><span><?= Html::e($author) ?></span></a><a class="embed-provider" href="<?= Html::e($siteUrl) ?>" target="_blank" rel="noopener noreferrer"><?= Html::e($siteName) ?></a></header>
        <div class="embed-body"><?php foreach ($segments as $segment): ?><?php if (isset($segment['url'])): ?><a href="<?= Html::e($segment['url']) ?>" target="_blank" rel="noopener noreferrer ugc"><?= Html::e($segment['text']) ?></a><?php else: ?><?= Html::e($segment['text']) ?><?php endif; ?><?php endforeach; ?></div>
        <?php if ($imageUrl !== ''): ?>
            <a class="embed-image" href="<?= Html::e($url) ?>" target="_blank" rel="noopener noreferrer"><img src="<?= Html::e($imageUrl) ?>" alt="<?= Html::e($imageAlt) ?>"<?= $imageWidth > 0 && $imageHeight > 0 ? ' width="' . $imageWidth . '" height="' . $imageHeight . '"' : '' ?> loading="lazy"></a>
        <?php endif; ?>
        <?php foreach ($media as $link): ?>
            <section class="embed-link" data-card-kind="<?= Html::e($link['card_kind']) ?>">
                <?php if ($link['card_kind'] === 'audio'): ?>
                    <a class="embed-audio-summary" href="<?= Html::e($link['outbound_url']) ?>" target="_blank" rel="noopener noreferrer ugc">
                        <?php if ($link['image_url'] !== ''): ?><img src="<?= Html::e($link['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                        <span class="embed-link-copy"><strong><?= Html::e($link['title']) ?></strong><?php if ($link['description'] !== ''): ?><span><?= Html::e($link['description']) ?></span><?php endif; ?><small><?= Html::e($link['provider_name'] ?: $link['display_url']) ?></small></span>
                    </a>
                <?php endif; ?>
                <?php if ($link['audio_url'] !== '' || $link['embed_allowed']): ?>
                    <div class="embed-player" data-embed-player data-audio-url="<?= Html::e($link['audio_url']) ?>" data-player-url="<?= Html::e($link['embed_url']) ?>" data-player-origin="<?= Html::e($link['embed_origin']) ?>" data-player-height="<?= (int) $link['embed_height'] ?>" data-player-kind="<?= Html::e($link['link_type']) ?>" data-player-autoplay-param="<?= Html::e($link['embed_autoplay_param']) ?>" data-player-autoplay-value="<?= Html::e($link['embed_autoplay_value']) ?>">
                        <button class="embed-play" type="button" aria-label="<?= Html::e($link['title']) ?>">
                            <?php if ($link['card_kind'] !== 'audio' && $link['thumbnail_url'] !== ''): ?><picture><?php if ($link['thumbnail_mobile_url'] !== ''): ?><source media="(max-width: 759px)" srcset="<?= Html::e($link['thumbnail_mobile_url']) ?>"><?php endif; ?><img src="<?= Html::e($link['thumbnail_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></picture><?php endif; ?>
                            <span class="embed-play-icon" aria-hidden="true">▶</span><?php if ($link['card_kind'] !== 'audio'): ?><strong><?= Html::e($link['title']) ?></strong><?php endif; ?>
                        </button>
                    </div>
                <?php elseif ($link['card_kind'] !== 'audio' && $link['image_url'] !== ''): ?>
                    <a href="<?= Html::e($link['outbound_url']) ?>" target="_blank" rel="noopener noreferrer ugc"><img class="embed-link-image" src="<?= Html::e($link['image_url']) ?>" alt="" loading="lazy"></a>
                <?php endif; ?>
                <?php if ($link['card_kind'] !== 'audio'): ?><a class="embed-link-copy" href="<?= Html::e($link['outbound_url']) ?>" target="_blank" rel="noopener noreferrer ugc"><strong><?= Html::e($link['title']) ?></strong><?php if ($link['description'] !== ''): ?><span><?= Html::e($link['description']) ?></span><?php endif; ?><small><?= Html::e($link['provider_name'] ?: $link['display_url']) ?></small></a><?php endif; ?>
            </section>
        <?php endforeach; ?>
        <footer><a href="<?= Html::e($url) ?>" target="_blank" rel="noopener noreferrer"><time datetime="<?= Html::e($publishedAt) ?>"><?= Html::e($publishedLabel) ?></time></a></footer>
    </article>
    <?php if ($share): ?>
        <section class="share-code"><label for="embed-code"><?= Html::et('account.status_embed_code') ?></label><textarea id="embed-code" readonly rows="7" spellcheck="false"><?= Html::e($code) ?></textarea><a href="<?= Html::e($url) ?>"><?= Html::et('account.status_embed_open') ?></a></section>
    <?php endif; ?>
</main>
</body>
</html>
