<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = is_array($item ?? null) ? $item : [];
$contentId = (int) ($item['id'] ?? 0);
$links = $contentId > 0 ? status_links_for_content($contentId) : [];
$links = array_values(array_filter(
    $links,
    static fn (array $link): bool => !status_link_is_internal($link)
));

if ($links === []) {
    return '';
}
?>
<div class="status-links">
    <?php foreach ($links as $link): ?>
        <?= part('status/link-card', ['link' => $link]) ?>
    <?php endforeach; ?>
</div>
