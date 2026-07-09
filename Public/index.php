<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$authUser = auth();
$feed = (string) get('feed', 'all') === 'following' ? 'following' : 'all';

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
        <main class="home-feed-section home-feed-app stack">
            <?= public_home_feed_html($feed, $authUser) ?>
        </main>
        <?= public_sidebar() ?>
    </section>
    <?php
});
