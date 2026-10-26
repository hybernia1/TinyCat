<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$authUser = \TinyCat\Users\Authentication::auth();
$feed = (string) \TinyCat\Http\Web::get('feed', 'all') === 'following' ? 'following' : 'all';
$editor = $authUser !== null ? \TinyCat\Status\ViewData::status_editor_view_data() : [];

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Site\Presentation::site_home_title(),
    'current' => '/',
    'meta' => [
        'description' => \TinyCat\Site\Presentation::site_meta_description(),
        'url' => '/',
        'image' => \TinyCat\Site\Presentation::site_meta_image_url(),
        'type' => 'website',
    ],
], static function () use ($authUser, $feed, $editor): void {
    ?>
    <section class="public-layout">
        <div class="home-feed-app stack">
            <?php if ($authUser !== null && \TinyCat\Moderation\Restrictions::user_is_muted($authUser)): ?>
                <div class="alert alert-warning">
                    <?= \TinyCat\Support\Html::icon('lock') ?> <span><?= \TinyCat\Support\Html::et('moderation.messages.account_muted', ['until' => \TinyCat\Support\Dates::displayDateTime(\TinyCat\Moderation\Restrictions::user_muted_until($authUser))]) ?></span>
                </div>
            <?php elseif ($authUser !== null): ?>
                <?= \TinyCat\Status\Views::composer([
                    'action' => $feed === 'following' ? '/?feed=following' : '/',
                    'user' => $authUser,
                    'editor' => $editor,
                ]) ?>
            <?php endif; ?>
            <div class="home-feed-section stack">
                <?= \TinyCat\Status\Views::homeFeed(\TinyCat\Status\ViewData::public_home_feed_data($feed, $authUser)) ?>
            </div>
        </div>
        <?= \TinyCat\Site\Presentation::public_sidebar() ?>
    </section>
    <?php
});
