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
        <main class="home-feed-section home-feed-app stack">
            <header class="home-intro stack stack-gap-4">
                <h1 class="text-2xl m-0"><?= e(site_home_title()) ?></h1>
                <?php if (site_home_intro() !== ''): ?>
                    <p class="text-muted m-0"><?= nl2br(e(site_home_intro())) ?></p>
                <?php endif; ?>
            </header>
            <?= public_home_feed_html($feed, $authUser) ?>
        </main>
        <?= public_sidebar() ?>
    </section>
    <?php
});
