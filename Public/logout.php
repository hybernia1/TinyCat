<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (is_post()) {
    csrf_require();
    auth_logout();
    flash('success', t('auth.logged_out'));
    redirect((string) config('auth.login_url', '/login'));
}

require_auth();

layout('layout', [
    'title' => t('auth.logout_title'),
    'current' => '/logout',
    'meta' => [
        'description' => t('auth.logout_intro'),
        'url' => '/logout',
        'robots' => 'noindex,nofollow',
    ],
], static function (): void {
    ?>
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= icon('logout') ?> <?= et('auth.logout_title') ?></h1>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= et('auth.logout_intro') ?></p>
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button class="btn btn-danger" type="submit"><?= icon('logout') ?> <span><?= et('common.logout') ?></span></button>
            </form>
        </div>
    </article>
    <?php
});
