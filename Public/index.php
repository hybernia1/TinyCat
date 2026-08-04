<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$authUser = auth();
$feed = (string) get('feed', 'all') === 'following' ? 'following' : 'all';

layout('layout', [
    'title' => site_home_title(),
    'current' => '/',
    'meta' => [
        'description' => site_meta_description(),
        'url' => '/',
        'image' => site_meta_image_url(),
        'type' => 'website',
    ],
], static function () use ($authUser, $feed): void {
    ?>
    <section class="public-layout">
        <div class="home-feed-section home-feed-app stack">
            <?= part('status/home-feed', public_home_feed_data($feed, $authUser)) ?>
        </div>
        <?= public_sidebar() ?>
    </section>
    <?php
});
