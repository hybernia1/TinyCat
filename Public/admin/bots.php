<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();
bot_schema_ensure();

$isApi = route_path() === '/api/admin/bots';

if (!$isApi && is_post() && (string) post('action', '') === 'rotate_cron_token') {
    csrf_require();
    bot_cron_token_rotate();
    flash('success', t('bots.messages.token_rotated'));
    redirect('/admin/bots');
}

if ($isApi && method() === 'GET') {
    api_ok(tc_admin_bots_payload());
}

if ($isApi && in_array(method(), ['POST', 'PATCH'], true)) {
    api_endpoint(method(), static function (): never {
        csrf_require();
        $id = method() === 'PATCH' ? max(1, (int) input('id', 0)) : null;

        if ($id !== null && bot_source_find($id) === null) {
            api_error(t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        $payload = tc_admin_bot_source_payload();
        if ($id === null) {
            $id = (int) insert('bot_sources', $payload + ['created_at' => date_db()]);
            $message = t('bots.messages.created');
        } else {
            update('bot_sources', $payload, ['id' => $id]);
            $message = t('bots.messages.saved');
        }

        api_ok(tc_admin_bots_payload($id), $message);
    });
}

if ($isApi && method() === 'DELETE') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) input('id', 0));

        if (bot_source_find($id) === null) {
            api_error(t('bots.messages.not_found'), 404, 'bot_source_not_found');
        }

        delete('bot_feed_items', ['source_id' => $id]);
        delete('bot_sources', ['id' => $id]);
        api_ok(tc_admin_bots_payload(), t('bots.messages.deleted'));
    });
}

layout('layout', [
    'title' => t('bots.title'),
    'current' => '/admin/bots',
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="bot-source-create-modal">' . icon('plus') . ' <span>' . et('bots.new_source') . '</span></button>',
], static function (): void {
    $cronToken = bot_cron_token(true);
    $cronUrl = absolute_url('/cron.php');
    $cronQueryUrl = $cronUrl . '?bearer=' . rawurlencode($cronToken);
    ?>
    <section class="card mb-4">
        <div class="card-header split">
            <div>
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('settings') ?> <?= et('bots.cron_title') ?></h2>
                <p class="text-muted mb-0"><?= et('bots.cron_intro') ?></p>
            </div>
            <form method="post" action="/admin/bots" data-confirm="<?= et('bots.cron_rotate_confirm') ?>">
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
    <section class="card">
        <div class="card-header split">
            <div>
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('link') ?> <?= et('bots.title') ?></h1>
                <p class="text-muted mb-0"><?= et('bots.intro') ?></p>
            </div>
        </div>
        <div class="card-body" id="bots-list">
            <?= tc_admin_bots_html() ?>
        </div>
    </section>
    <?= tc_admin_bot_source_modal(null) ?>
    <?php
});

function tc_admin_bots_payload(?int $id = null): array
{
    $filterBotId = tc_admin_bots_filter_id();
    $sources = bot_sources($filterBotId > 0 ? $filterBotId : null);
    $data = [
        'items' => array_map('bot_source_resource', $sources),
        'bots' => array_map(static fn (array $user): array => [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'status' => (string) ($user['status'] ?? ''),
        ], bot_users()),
        'filter_bot_user_id' => $filterBotId,
    ];

    if ($id !== null) {
        $source = bot_source_find($id);
        $data['id'] = $id;
        $data['item'] = $source !== null ? bot_source_resource($source) : null;
    }

    return api_payload($data, static fn (): array => $data + ['html' => tc_admin_bots_html()]);
}

function tc_admin_bots_filter_id(): int
{
    $id = max(0, (int) get('bot', 0));
    return $id > 0 && (int) val('SELECT COUNT(*) FROM users WHERE id = ? AND role = ?', [$id, 'bot']) > 0 ? $id : 0;
}

function tc_admin_bots_api_url(): string
{
    $query = ['view' => 'html'];
    $filterBotId = tc_admin_bots_filter_id();
    if ($filterBotId > 0) {
        $query['bot'] = $filterBotId;
    }

    return '/api/admin/bots?' . http_build_query($query);
}

function tc_admin_bot_source_payload(): array
{
    $botUserId = max(0, (int) input('bot_user_id', 0));
    $name = trim((string) input('name', ''));
    $feedUrl = trim((string) input('feed_url', ''));
    $interval = (int) input('interval_minutes', 60);
    $template = trim((string) input('post_template', ''));
    $errors = [];

    if ((int) val('SELECT COUNT(*) FROM users WHERE id = ? AND role = ?', [$botUserId, 'bot']) < 1) {
        $errors['bot_user_id'][] = t('bots.validation.bot');
    }
    if ($name === '' || strlen($name) > 120) {
        $errors['name'][] = t('bots.validation.name');
    }
    if (!LinkMetadata::isSafeRemoteUrl($feedUrl) || strlen($feedUrl) > 2048) {
        $errors['feed_url'][] = t('bots.validation.feed_url');
    }
    if ($interval < 5 || $interval > 43200) {
        $errors['interval_minutes'][] = t('bots.validation.interval');
    }
    if ($template === '' || strlen($template) > 2000) {
        $errors['post_template'][] = t('bots.validation.template');
    }
    if ($errors !== []) {
        api_validation($errors);
    }

    return [
        'bot_user_id' => $botUserId,
        'name' => $name,
        'feed_url' => $feedUrl,
        'interval_minutes' => $interval,
        'post_template' => $template,
        'enabled' => in_array(input('enabled', null), [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
        'next_run_at' => null,
        'last_error' => null,
    ];
}

function tc_admin_bots_html(): string
{
    $bots = bot_users();
    $filterBotId = tc_admin_bots_filter_id();
    $sources = bot_sources($filterBotId > 0 ? $filterBotId : null);
    ob_start();
    ?>
    <?php if ($bots === []): ?>
        <div class="alert alert-info"><?= et('bots.no_bots') ?></div>
    <?php else: ?>
        <div class="split gap-3 mb-4">
            <form class="cluster gap-2" method="get" action="/admin/bots">
                <label class="field">
                    <span class="label"><?= et('bots.filter_by_bot') ?></span>
                    <select class="select" name="bot">
                        <option value="0"><?= et('bots.all_bots') ?></option>
                        <?php foreach ($bots as $bot): ?>
                            <option value="<?= e((int) $bot['id']) ?>"<?= $filterBotId === (int) $bot['id'] ? ' selected' : '' ?>>@<?= e((string) $bot['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-secondary btn-sm" type="submit"><?= icon('filter') ?> <span><?= et('common.apply_filters') ?></span></button>
                <?php if ($filterBotId > 0): ?><a class="btn btn-ghost btn-sm" href="/admin/bots"><?= icon('close') ?> <span><?= et('common.clear_filters') ?></span></a><?php endif; ?>
            </form>
            <div class="cluster gap-2">
                <span class="badge"><?= et('bots.sources_count', ['count' => count($sources)]) ?></span>
                <span class="badge badge-primary"><?= et('bots.active_sources_count', ['count' => count(array_filter($sources, static fn (array $source): bool => (bool) ($source['enabled'] ?? false)))]) ?></span>
            </div>
        </div>
        <?php if ($sources === []): ?>
            <div class="alert alert-info"><?= et($filterBotId > 0 ? 'bots.no_sources_filtered' : 'bots.no_sources') ?></div>
        <?php else: ?>
        <div class="stack stack-gap-12">
            <?php foreach ($sources as $source): ?>
                <?php $id = (int) ($source['id'] ?? 0); ?>
                <article class="result-item split">
                    <div class="stack gap-1">
                        <div class="cluster gap-2">
                            <strong><?= e((string) ($source['name'] ?? '')) ?></strong>
                            <span class="badge<?= (bool) ($source['enabled'] ?? false) ? ' badge-primary' : '' ?>"><?= et((bool) ($source['enabled'] ?? false) ? 'bots.enabled' : 'bots.disabled') ?></span>
                            <a class="badge" href="/admin/bots?bot=<?= e((int) ($source['bot_user_id'] ?? 0)) ?>">@<?= e((string) ($source['username'] ?? '')) ?></a>
                        </div>
                        <a class="text-muted" href="<?= e((string) ($source['feed_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) ($source['feed_url'] ?? '')) ?></a>
                        <small class="text-muted"><?= et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?><?= !empty($source['next_run_at']) ? ' · ' . et('bots.next_run', ['time' => datetime((string) $source['next_run_at'])]) : '' ?></small>
                        <?php if (!empty($source['last_error'])): ?><small class="text-danger"><?= e((string) $source['last_error']) ?></small><?php endif; ?>
                    </div>
                    <div class="cluster gap-2">
                        <button class="btn btn-secondary btn-sm btn-icon" type="button" data-modal-open="bot-source-edit-<?= e($id) ?>" aria-label="<?= et('common.edit') ?>"><?= icon('edit') ?></button>
                        <form method="post" action="<?= e(tc_admin_bots_api_url()) ?>" data-ajax-form data-ajax-target="#bots-list" data-confirm="<?= et('bots.delete_confirm') ?>">
                            <?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><input type="hidden" name="id" value="<?= e($id) ?>">
                            <button class="btn btn-danger btn-sm btn-icon" type="submit" aria-label="<?= et('common.delete') ?>"><?= icon('trash') ?></button>
                        </form>
                    </div>
                </article>
                <?= tc_admin_bot_source_modal($source) ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    return trim((string) ob_get_clean());
}

function tc_admin_bot_source_modal(?array $source): string
{
    $create = $source === null;
    $source ??= [];
    $id = (int) ($source['id'] ?? 0);
    $bots = bot_users();
    if ($create && tc_admin_bots_filter_id() > 0) {
        $source['bot_user_id'] = tc_admin_bots_filter_id();
    }
    ob_start();
    ?>
    <?php if (!$create): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
    <div class="stack">
        <div class="grid sm:grid-2">
            <label class="field"><span class="label"><?= et('bots.bot') ?></span><select class="select" name="bot_user_id" required><?php foreach ($bots as $bot): ?><option value="<?= e((int) $bot['id']) ?>"<?= (int) ($source['bot_user_id'] ?? 0) === (int) $bot['id'] ? ' selected' : '' ?>>@<?= e((string) $bot['username']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="label"><?= et('bots.source_name') ?></span><input class="input" name="name" maxlength="120" value="<?= e((string) ($source['name'] ?? '')) ?>" required></label>
        </div>
        <label class="field"><span class="label"><?= et('bots.feed_url') ?></span><input class="input" type="url" name="feed_url" maxlength="2048" value="<?= e((string) ($source['feed_url'] ?? '')) ?>" placeholder="https://example.com/feed/" required></label>
        <label class="field"><span class="label"><?= et('bots.interval') ?></span><input class="input" type="number" name="interval_minutes" min="5" max="43200" value="<?= e((int) ($source['interval_minutes'] ?? 60)) ?>" required><span class="help"><?= et('bots.interval_help') ?></span></label>
        <label class="field"><span class="label"><?= et('bots.template') ?></span><textarea class="textarea" name="post_template" rows="8" maxlength="2000" required><?= e((string) ($source['post_template'] ?? bot_source_default_template())) ?></textarea><span class="help"><?= et('bots.template_help') ?></span></label>
        <label class="check"><input type="checkbox" name="enabled" value="1"<?= $create || (bool) ($source['enabled'] ?? false) ? ' checked' : '' ?>> <span><?= et('bots.enabled') ?></span></label>
    </div>
    <?php
    $body = trim((string) ob_get_clean());

    return render('modals/layout', [
        'id' => $create ? 'bot-source-create-modal' : 'bot-source-edit-' . $id,
        'title' => t($create ? 'bots.new_source' : 'bots.edit_source'),
        'icon' => 'link',
        'action' => tc_admin_bots_api_url(),
        'method' => $create ? 'POST' : 'PATCH',
        'ajax' => true,
        'target' => '#bots-list',
        'closeOnSuccess' => true,
        'size' => 'modal-panel-lg',
        'body' => $body,
        'footer' => '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button><button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.save') . '</span></button>',
    ]);
}
