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
$view = is_array($item['_view'] ?? null) ? $item['_view'] : [];
$url = (string) ($view['status_url'] ?? '');
$label = (string) ($view['permalink_label'] ?? '');
$time = is_array($view['time'] ?? null) ? $view['time'] : [];

if ($createdAt === '' || $contentId < 1 || $url === '') {
    return '';
}
?>
<a class="link-button public-content-meta status-time-button" href="<?= e($url) ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"<?= $openModal ? ' data-modal-open' : '' ?>>
    <time datetime="<?= e((string) ($time['iso'] ?? '')) ?>"><?= e((string) ($time['label'] ?? '')) ?></time>
</a>
