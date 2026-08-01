<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();

    if ((string) post('action', '') === 'rotate_cron_token') {
        bot_cron_token_rotate();
        flash('success', t('bots.messages.token_rotated'));
    }

    redirect('/admin/bots/cron');
}

$cronToken = bot_cron_token(true);
$cronUrl = absolute_url('/cron.php');
$cronQueryUrl = $cronUrl . '?bearer=' . rawurlencode($cronToken);

layout('layout', [
    'title' => t('bots.cron_title'),
    'current' => '/admin/bots/cron',
], static function () use ($cronToken, $cronUrl, $cronQueryUrl): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <div>
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('clock') ?> <?= et('bots.cron_title') ?></h1>
                <p class="text-muted mb-0"><?= et('bots.cron_intro') ?></p>
            </div>
            <form method="post" action="/admin/bots/cron" data-confirm="<?= et('bots.cron_rotate_confirm') ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="rotate_cron_token">
                <button class="btn btn-secondary btn-sm" type="submit"><?= icon('refresh') ?> <span><?= et('bots.cron_rotate') ?></span></button>
            </form>
        </div>
        <div class="card-body stack">
            <label class="field"><span class="label"><?= et('bots.cron_url') ?></span><input class="input" value="<?= e($cronUrl) ?>" readonly></label>
            <label class="field"><span class="label"><?= et('bots.cron_token') ?></span><input class="input" value="<?= e($cronToken) ?>" readonly></label>
            <label class="field"><span class="label"><?= et('bots.cron_query_url') ?></span><input class="input" value="<?= e($cronQueryUrl) ?>" readonly><span class="help"><?= et('bots.cron_query_help') ?></span></label>
            <div class="field"><span class="label"><?= et('bots.cron_example') ?></span><pre class="code-block"><code><?= e('curl -fsS -X POST -H "Authorization: Bearer ' . $cronToken . '" ' . $cronUrl) ?></code></pre></div>
            <div class="field"><span class="label"><?= et('bots.cron_cli_example') ?></span><pre class="code-block"><code><?= e('php "' . base_path('cron.php') . '"') ?></code></pre><span class="help"><?= et('bots.cron_cli_help') ?></span></div>
            <p class="help m-0"><?= et('bots.cron_help') ?></p>
        </div>
    </section>
    <?php
});
