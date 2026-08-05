<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$notFoundTitle = trim((string) ($notFoundTitle ?? t('public.not_found_title')));
$notFoundMessage = trim((string) ($notFoundMessage ?? t('public.not_found_intro')));
$notFoundCurrent = route_path((string) ($notFoundCurrent ?? route_path()));

if (http_response_code() < 400) {
    http_response_code(404);
}

layout('layout', [
    'title' => $notFoundTitle,
    'current' => $notFoundCurrent,
    'meta' => [
        'description' => $notFoundMessage,
        'robots' => 'noindex,follow',
    ],
], static function () use ($notFoundTitle, $notFoundMessage): void {
    ?>
    <section class="max-w-auth mx-auto" aria-labelledby="not-found-title">
        <article class="card">
            <div class="card-body stack stack-gap-16">
                <p class="text-2xl text-primary m-0" aria-hidden="true">404</p>
                <div class="stack stack-gap-8">
                    <h1 class="text-2xl m-0" id="not-found-title"><?= icon('alert') ?> <?= e($notFoundTitle) ?></h1>
                    <p class="text-muted m-0"><?= e($notFoundMessage) ?></p>
                </div>
                <div class="btn-group">
                    <a class="btn btn-primary" href="/"><?= icon('home') ?> <span><?= et('public.not_found_home') ?></span></a>
                    <a class="btn btn-secondary" href="/search"><?= icon('search') ?> <span><?= et('public.not_found_search') ?></span></a>
                </div>
            </div>
        </article>
    </section>
    <?php
});
