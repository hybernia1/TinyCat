<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

$authUser = auth();
$feed = (string) get('feed', 'all') === 'following' ? 'following' : 'all';
$currentFeedUrl = $feed === 'following' ? '/?feed=following' : '/';

if (is_post()) {
    csrf_require();
    status_handle_post(require_auth('/login'), $currentFeedUrl);
}

layout('layout', [
    'title' => site_name(),
    'current' => '/',
    'meta' => [
        'description' => t('public.home_meta', ['site' => site_name()]),
        'url' => '/',
        'image' => site_meta_image_url(),
        'type' => 'website',
    ],
], static function () use ($authUser, $feed): void {
    ?>
    <section class="public-layout">
        <main class="home-feed-section stack stack-gap-14">
            <?= public_home_feed_html($feed, $authUser) ?>
        </main>
        <?= public_sidebar() ?>
    </section>
    <?php
});
