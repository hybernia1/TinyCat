<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$createdAt = (string) ($created_at ?? '');
$contentId = max(0, (int) ($content_id ?? 0));
$openModal = (bool) ($open_modal ?? true);
$item = is_array($item ?? null) ? $item : ['id' => $contentId];
$label = status_permalink_label($item);

if ($createdAt === '' || $contentId < 1) {
    return '';
}
?>
<a class="link-button public-content-meta status-time-button" href="<?= e(status_url($contentId)) ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"<?= $openModal ? ' data-modal-open' : '' ?>>
    <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
</a>
