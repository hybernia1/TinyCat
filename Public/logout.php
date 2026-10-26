<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (\TinyCat\Http\Request::current()->isPost()) {
    \TinyCat\Security\Csrf::requireToken();
    \TinyCat\Users\Authentication::authLogout();
    \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('auth.logged_out'));
    \TinyCat\Http\Web::redirect('/login');
}

\TinyCat\Users\Access::requireAuth();

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('auth.logout_title'),
    'current' => '/logout',
    'meta' => [
        'description' => \TinyCat\Support\Localization::t('auth.logout_intro'),
        'url' => '/logout',
        'robots' => 'noindex,nofollow',
    ],
], static function (): void {
    ?>
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('logout') ?> <?= \TinyCat\Support\Html::et('auth.logout_title') ?></h1>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('auth.logout_intro') ?></p>
            <form method="post" action="/logout" data-confirm="<?= \TinyCat\Support\Html::et('auth.logout_confirm') ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('auth.logout_title') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('common.logout') ?>" data-confirm-cancel="<?= \TinyCat\Support\Html::et('common.cancel') ?>" data-confirm-variant="danger">
                <?= \TinyCat\Security\Csrf::field() ?>
                <button class="btn btn-danger" type="submit"><?= \TinyCat\Support\Html::icon('logout') ?> <span><?= \TinyCat\Support\Html::et('common.logout') ?></span></button>
            </form>
        </div>
    </article>
    <?php
});
