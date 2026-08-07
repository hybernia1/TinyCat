<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$url = status_image_url($item);
$contentId = max(0, (int) ($item['id'] ?? 0));
$openModal = (bool) ($open_modal ?? false);

if ($url === '') {
    return;
}
?>
<figure class="status-image">
    <?php if ($contentId > 0 && $openModal): ?><a class="status-image-link" href="<?= e(status_url($contentId)) ?>" data-modal-open><?php endif; ?>
        <img src="<?= e($url) ?>" alt="<?= e(status_image_alt_text($item)) ?>" loading="lazy"<?= (int) ($item['image_width'] ?? 0) > 0 ? ' width="' . e((int) $item['image_width']) . '"' : '' ?><?= (int) ($item['image_height'] ?? 0) > 0 ? ' height="' . e((int) $item['image_height']) . '"' : '' ?>>
    <?php if ($contentId > 0 && $openModal): ?></a><?php endif; ?>
</figure>
