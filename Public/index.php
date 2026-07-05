<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

layout('layout', [
    'title' => site_name(),
    'current' => '/',
], static function (): void {
    ?>
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= icon('home') ?> <?= e(site_name()) ?></h1>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= et('admin.dashboard_intro') ?></p>
            <div class="btn-group">
                <a class="btn btn-primary" href="/admin"><?= icon('dashboard') ?> <span><?= et('install.open_admin') ?></span></a>
                <a class="btn btn-secondary" href="/install"><?= icon('settings') ?> <span><?= et('install.step_done') ?></span></a>
            </div>
        </div>
    </article>
    <?php
});
