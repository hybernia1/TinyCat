<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$link = is_array($link ?? null) ? $link : [];
$type = (string) ($link['link_type'] ?? 'link');
$provider = (string) ($link['provider'] ?? 'web');
$url = (string) ($link['normalized_url'] ?? '');
$title = trim((string) ($link['title'] ?? ''));
$description = trim((string) ($link['description'] ?? ''));
$imageUrl = trim((string) ($link['image_url'] ?? ''));
$displayUrl = status_link_display_url($link);

if ($url === '') {
    return '';
}

if ($title === '') {
    $title = $displayUrl !== '' ? $displayUrl : $url;
}

$embedUrl = status_video_embed_url($link);
$thumbnailSources = status_video_thumbnail_sources($link);
$thumbnailUrl = (string) ($thumbnailSources['fallback'] ?? '');
$thumbnailWebpUrl = (string) ($thumbnailSources['webp'] ?? '');
?>
<?php if ($type === 'video' && status_video_embed_allowed($embedUrl)): ?>
    <div class="status-video-card" data-status-video data-embed-url="<?= e($embedUrl) ?>">
        <button class="status-video-placeholder" type="button" data-status-video-load aria-label="<?= e($title) ?>">
            <?php if ($thumbnailUrl !== ''): ?>
                <picture class="status-video-thumb">
                    <?php if ($thumbnailWebpUrl !== ''): ?>
                        <source srcset="<?= e($thumbnailWebpUrl) ?>" type="image/webp">
                    <?php endif; ?>
                    <img src="<?= e($thumbnailUrl) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                </picture>
            <?php endif; ?>
            <span class="status-video-play"><?= icon('play') ?></span>
            <span class="status-video-copy">
                <strong><?= e($title) ?></strong>
                <small><?= e($description !== '' ? $description : $provider) ?></small>
            </span>
        </button>
    </div>
<?php else: ?>
    <a class="status-link-card<?= $imageUrl !== '' ? ' has-image' : '' ?>" href="<?= e($url) ?>" target="_blank" rel="nofollow noopener noreferrer ugc">
        <?php if ($imageUrl !== ''): ?>
            <span class="status-link-media" data-status-link-media>
                <img class="status-link-image" src="<?= e($imageUrl) ?>" alt="" loading="lazy" data-status-link-image>
                <span class="status-link-icon status-link-fallback-icon" data-status-link-fallback><?= icon('image') ?></span>
            </span>
        <?php else: ?>
            <span class="status-link-icon"><?= icon('external-link') ?></span>
        <?php endif; ?>
        <span class="status-link-copy">
            <strong><?= e($title) ?></strong>
            <?php if ($description !== '' && $description !== $displayUrl): ?>
                <span><?= e($description) ?></span>
            <?php endif; ?>
            <?php if ($displayUrl !== ''): ?>
                <small><?= e($displayUrl) ?></small>
            <?php endif; ?>
        </span>
    </a>
<?php endif; ?>
