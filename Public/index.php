<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$authUser = auth();
$feed = (string) get('feed', 'all') === 'following' ? 'following' : 'all';
$editor = $authUser !== null ? status_editor_view_data() : [];

layout('layout', [
    'title' => site_home_title(),
    'current' => '/',
    'meta' => [
        'description' => site_meta_description(),
        'url' => '/',
        'image' => site_meta_image_url(),
        'type' => 'website',
    ],
], static function () use ($authUser, $feed, $editor): void {
    ?>
    <section class="public-layout">
        <div class="home-feed-app stack">
            <?php if ($authUser !== null && user_is_muted($authUser)): ?>
                <div class="alert alert-warning">
                    <?= icon('lock') ?> <span><?= et('moderation.messages.account_muted', ['until' => datetime(user_muted_until($authUser))]) ?></span>
                </div>
            <?php elseif ($authUser !== null): ?>
                <?= part('status/composer', [
                    'action' => $feed === 'following' ? '/?feed=following' : '/',
                    'user' => $authUser,
                    'editor' => $editor,
                ]) ?>
            <?php endif; ?>
            <div class="home-feed-section stack">
                <?= part('status/home-feed', public_home_feed_data($feed, $authUser)) ?>
            </div>
        </div>
        <?= public_sidebar() ?>
    </section>
    <?php
});
