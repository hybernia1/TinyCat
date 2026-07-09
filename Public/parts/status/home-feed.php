<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$feed = (string) ($feed ?? 'all');
$feed = $feed === 'following' ? 'following' : 'all';
$user = is_array($user ?? null) ? $user : null;
$currentFeedUrl = (string) ($current_feed_url ?? ($feed === 'following' ? '/?feed=following' : '/'));
$followingLoginRequired = (bool) ($following_login_required ?? false);
$limit = (int) ($limit ?? public_status_page_limit());
$items = is_array($items ?? null) ? $items : [];
$feedId = (string) ($feed_id ?? ('status-feed-' . $feed));
?>
<?php if ($user !== null && user_is_muted($user)): ?>
    <div class="alert alert-warning">
        <?= icon('lock') ?> <span><?= et('moderation.messages.account_muted', ['until' => datetime(user_muted_until($user))]) ?></span>
    </div>
<?php elseif ($user !== null): ?>
    <?= status_composer($currentFeedUrl, $user) ?>
<?php endif; ?>

<nav class="feed-switch home-feed-switch" aria-label="<?= et('public.feed_title') ?>">
    <a class="feed-switch-link" href="/" data-ajax data-url="<?= e(public_home_feed_api_url('all', true)) ?>" data-ajax-target=".home-feed-section" data-history="/"<?= $feed === 'all' ? ' aria-current="page"' : '' ?>>
        <?= et('public.feed_all') ?>
    </a>
    <a class="feed-switch-link" href="/?feed=following" data-ajax data-url="<?= e(public_home_feed_api_url('following', true)) ?>" data-ajax-target=".home-feed-section" data-history="/?feed=following"<?= $feed === 'following' ? ' aria-current="page"' : '' ?>>
        <?= et('public.feed_following') ?>
    </a>
</nav>

<?php if ($followingLoginRequired): ?>
    <div class="alert alert-info cluster">
        <span><?= et('public.feed_following_login') ?></span>
        <a class="btn btn-secondary btn-sm" href="<?= e(status_login_url('', $currentFeedUrl)) ?>"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
    </div>
<?php else: ?>
    <?php if ($items === []): ?>
        <div class="alert alert-info" data-status-empty><?= et($feed === 'following' ? 'public.feed_empty_following' : 'public.feed_empty') ?></div>
    <?php endif; ?>
    <div class="status-feed" id="<?= e($feedId) ?>" data-status-feed>
        <?= status_feed_html($items, $currentFeedUrl, $user) ?>
    </div>
    <?= status_feed_more_control($feedId, 'home', count($items), $limit, ['feed' => $feed]) ?>
<?php endif; ?>
