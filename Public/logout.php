<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (is_post()) {
    csrf_require();
    auth_logout();
    flash('success', 'Byla jsi odhlášena.');
    redirect((string) config('auth.login_url', '/login'));
}

require_auth();

layout('layout', [
    'title' => 'Logout',
    'current' => '/logout',
], static function (): void {
    ?>
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= icon('logout') ?> Odhlášení</h1>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0">Potvrď odhlášení z aktuální session.</p>
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button class="btn btn-danger" type="submit"><?= icon('logout') ?> <span>Odhlásit</span></button>
            </form>
        </div>
    </article>
    <?php
});
