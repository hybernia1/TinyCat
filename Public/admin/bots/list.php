<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post() && (string) post('action', '') === 'run_source') {
    csrf_require();
    $sourceId = max(0, (int) post('source_id', 0));
    $source = $sourceId > 0 ? bot_source_find($sourceId) : null;
    $botId = (int) ($source['bot_user_id'] ?? 0);
    $bot = $botId > 0 ? one('SELECT id, status FROM users WHERE id = ? AND role = ? LIMIT 1', [$botId, 'bot']) : null;

    if ($source === null || $bot === null) {
        flash('error', t('bots.messages.not_found'));
    } elseif ((string) ($bot['status'] ?? '') !== 'active') {
        flash('error', t('bots.detail_bot_inactive'));
    } elseif (!(bool) ($source['enabled'] ?? false)) {
        flash('error', t('bots.detail_source_disabled'));
    } else {
        $result = bot_run_source($source, true);
        $failed = (string) ($result['status'] ?? '') === 'error';
        flash($failed ? 'error' : 'success', t($failed ? 'bots.detail_run_failed' : 'bots.detail_run_finished'));
    }

    redirect('/admin/bots/list' . ($botId > 0 ? '?bot=' . $botId : ''));
}

layout('layout', [
    'title' => t('bots.sources_title'),
    'current' => '/admin/bots/list',
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="bot-source-create-modal">' . icon('plus') . ' <span>' . et('bots.new_source') . '</span></button>',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('rss') ?> <?= et('bots.sources_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="bots-filter-modal">
                <?= icon('filter') ?> <span><?= et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="bots-list">
            <?= BotAdmin::html() ?>
        </div>
    </section>
    <?= BotAdmin::sourceModal(null) ?>
    <?= BotAdmin::filterModal() ?>
    <?php
});
