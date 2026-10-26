<?php
declare(strict_types=1);

use TinyCat\Support\Html;
use TinyCat\Support\View;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$currentFeedUrl = (string) ($currentFeedUrl ?? '');
$feed = (string) ($feed ?? '');
$feedId = (string) ($feedId ?? '');
$feedMore = is_array($feedMore ?? null) ? $feedMore : [];
$followingLoginRequired = (bool) ($followingLoginRequired ?? false);
$items = is_array($items ?? null) ? $items : [];
$user = is_array($user ?? null) ? $user : null;
$prepared = is_array($prepared ?? null) ? $prepared : [];
?>
<h1 class="sr-only"><?= Html::et($feed === 'following' ? 'public.feed_title_following' : 'public.feed_title') ?></h1>

<nav class="feed-switch home-feed-switch" aria-label="<?= Html::et('public.feed_title') ?>">
    <a class="feed-switch-link" href="/" data-ajax data-url="<?= Html::e($prepared['all_feed_url']) ?>" data-ajax-target=".home-feed-section" data-history="/"<?= $feed === 'all' ? ' aria-current="page"' : '' ?>>
        <?= Html::et('public.feed_all') ?>
    </a>
    <a class="feed-switch-link" href="/?feed=following" data-ajax data-url="<?= Html::e($prepared['following_feed_url']) ?>" data-ajax-target=".home-feed-section" data-history="/?feed=following"<?= $feed === 'following' ? ' aria-current="page"' : '' ?>>
        <?= Html::et('public.feed_following') ?>
    </a>
</nav>

<?php if ($followingLoginRequired): ?>
    <?php $loginUrl = $prepared['status_login_url']; ?>
    <div class="alert alert-info cluster">
        <span><?= Html::et('public.feed_following_login') ?></span>
        <a class="btn btn-secondary btn-sm" href="<?= Html::e($loginUrl) ?>" data-modal-open="<?= Html::e($prepared['auth_modal_id']) ?>" data-modal-url="<?= Html::e($prepared['auth_modal_url']) ?>"><?= Html::icon('login') ?> <span><?= Html::et('common.login') ?></span></a>
    </div>
<?php else: ?>
    <?php if ($items === []): ?>
        <div class="alert alert-info" data-status-empty><?= Html::et($feed === 'following' ? 'public.feed_empty_following' : 'public.feed_empty') ?></div>
    <?php endif; ?>
    <div class="status-feed" id="<?= Html::e($feedId) ?>" data-status-feed>
        <?= View::part('Status/feed', ['items' => $items, 'action' => $currentFeedUrl, 'user' => $user]) ?>
    </div>
    <?= \TinyCat\Status\Views::feedMore($feedMore) ?>
<?php endif; ?>
