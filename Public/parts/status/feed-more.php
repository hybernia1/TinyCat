<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$feedId = (string) ($feed_id ?? '');
$context = (string) ($context ?? 'home');
$loaded = (int) ($loaded ?? 0);
$limit = (int) ($limit ?? public_status_page_limit());
$params = is_array($params ?? null) ? $params : [];
$nextUrl = status_feed_next_url($context, $loaded, $limit, $params);
?>
<div class="status-feed-more" data-status-feed-more data-status-feed-target="#<?= e($feedId) ?>" data-status-feed-url="<?= e($nextUrl) ?>">
    <button class="btn btn-secondary status-feed-more-button" type="button" data-status-feed-load>
        <?= icon('plus') ?> <span><?= et('public.load_more_posts') ?></span>
    </button>
    <span class="status-feed-more-state" data-status-feed-state hidden><?= et('public.loading_posts') ?></span>
</div>
