<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();
require_once dirname(__DIR__, 2) . '/update.php';

if (is_post()) {
    csrf_require();
    try {
        $result = tinycat_run_updates();
        flash('success', (string) ($result['message'] ?? t('updates.completed')));
    } catch (Throwable $exception) {
        flash('error', t('updates.failed', ['message' => $exception->getMessage()]));
    }
    redirect('/admin/updates');
}

layout('layout', [
    'title' => t('updates.title'),
    'current' => '/admin/updates',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('database') ?> <?= et('updates.title') ?></h2></div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= et('updates.intro') ?></p>
            <form method="post" action="/admin/updates">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"><?= icon('refresh') ?> <span><?= et('updates.run') ?></span></button>
            </form>
            <p class="table-meta mb-0"><?= et('updates.cli_hint') ?></p>
        </div>
    </section>
    <?php
});
