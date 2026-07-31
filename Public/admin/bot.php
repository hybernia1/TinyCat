<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$admin = require_admin();
$botId = max(0, (int) get('id', 0));
$bot = $botId > 0 ? one('SELECT * FROM users WHERE id = ? AND role = ? LIMIT 1', [$botId, 'bot']) : null;

if ($bot === null) {
    http_response_code(404);
    layout('layout', [
        'title' => t('bots.detail_not_found'),
        'current' => '/admin/bots',
    ], static function (): void {
        ?><div class="alert alert-info"><?= et('bots.detail_not_found') ?></div><?php
    });
    return;
}

if (is_post()) {
    csrf_require();
    $action = (string) post('action', '');

    if ($action === 'save_bot') {
        $status = (string) post('status', '');
        $allowedStatuses = ['active', 'waiting', 'ban'];
        $bio = plain_text_limit((string) post('bio', ''), 500);
        if (!in_array($status, $allowedStatuses, true)) {
            flash('error', t('users.validation.status_invalid'));
        } else {
            $avatar = tc_admin_user_avatar_change($bot);
            $payload = ['status' => $status, 'bio' => $bio];
            if ($avatar['changed']) {
                $payload['avatar_config'] = $avatar['json'];
            }

            try {
                update('users', $payload, ['id' => $botId]);
                if ($status !== 'active') {
                    update('bot_sources', ['enabled' => 0], ['bot_user_id' => $botId]);
                }
            } catch (Throwable $exception) {
                if ($avatar['uploaded']) {
                    Avatar::delete($avatar['config']);
                }
                throw $exception;
            }

            if ($avatar['changed']) {
                Avatar::delete($bot['avatar_config'] ?? null, $avatar['config']);
            }
            flash('success', t('bots.messages.saved'));
        }
        redirect('/admin/bots/' . $botId);
    }

    $sourceId = max(0, (int) post('source_id', 0));
    $source = $sourceId > 0 ? bot_source_find($sourceId) : null;

    if ($source === null || (int) ($source['bot_user_id'] ?? 0) !== $botId) {
        flash('error', t('bots.messages.not_found'));
        redirect('/admin/bots/' . $botId);
    }

    if ($action === 'save_source') {
        $name = trim((string) post('name', ''));
        $feedUrl = trim((string) post('feed_url', ''));
        $interval = (int) post('interval_minutes', 60);
        $template = trim((string) post('post_template', ''));
        $errors = [];
        if ($name === '' || strlen($name) > 120) {
            $errors[] = t('bots.validation.name');
        }
        if (!LinkMetadata::isSafeRemoteUrl($feedUrl) || strlen($feedUrl) > 2048) {
            $errors[] = t('bots.validation.feed_url');
        }
        if ($interval < 5 || $interval > 43200) {
            $errors[] = t('bots.validation.interval');
        }
        if ($template === '' || strlen($template) > 2000) {
            $errors[] = t('bots.validation.template');
        }
        if ($errors !== []) {
            flash('error', implode(' ', $errors));
        } else {
            $enabled = in_array(post('enabled', null), [true, 1, '1', 'true', 'on'], true)
                && (string) ($bot['status'] ?? '') === 'active';
            update('bot_sources', [
                'name' => $name,
                'feed_url' => $feedUrl,
                'interval_minutes' => $interval,
                'post_template' => $template,
                'enabled' => $enabled ? 1 : 0,
                'next_run_at' => $enabled ? date_db() : null,
                'last_error' => null,
            ], ['id' => $sourceId]);
            flash('success', t('bots.messages.saved'));
        }
    } elseif ($action === 'toggle_source') {
        $enabled = (bool) ($source['enabled'] ?? false);
        update('bot_sources', [
            'enabled' => $enabled ? 0 : 1,
            'next_run_at' => $enabled ? null : date_db(),
            'last_error' => null,
        ], ['id' => $sourceId]);
        flash('success', t('bots.messages.saved'));
    } elseif ($action === 'run_source') {
        if ((string) ($bot['status'] ?? '') !== 'active') {
            flash('error', t('bots.detail_bot_inactive'));
        } elseif (!(bool) ($source['enabled'] ?? false)) {
            flash('error', t('bots.detail_source_disabled'));
        } else {
            $result = bot_run_source($source, true);
            $messageKey = (string) ($result['status'] ?? '') === 'error'
                ? 'bots.detail_run_failed'
                : 'bots.detail_run_finished';
            flash((string) ($result['status'] ?? '') === 'error' ? 'error' : 'success', t($messageKey));
        }
    }

    redirect('/admin/bots/' . $botId);
}

$sources = bot_sources($botId);
$runs = tc_admin_bot_detail_runs($botId);
$stats = tc_admin_bot_detail_stats($botId, $sources, $runs);
$lastPosts = all(
    'SELECT id, body, published_at FROM content WHERE author_id = ? ORDER BY published_at DESC, id DESC LIMIT 8',
    [$botId]
);
$lastRun = $runs[0] ?? null;
$profileUrl = author_url($botId);

layout('layout', [
    'title' => '@' . (string) $bot['username'],
    'current' => '/admin/bots',
    'actions' => '<a class="btn btn-secondary btn-sm" href="/admin/bots">' . icon('arrow-left') . ' <span>' . et('common.back') . '</span></a>',
], static function () use ($bot, $sources, $runs, $stats, $lastPosts, $lastRun, $profileUrl): void {
    $botName = '@' . (string) ($bot['username'] ?? '');
    ?>
    <section class="card">
        <div class="card-header split">
            <div class="cluster gap-3">
                <div class="avatar avatar-lg"><?= user_avatar_html($bot, $botName) ?></div>
                <div>
                    <h1 class="text-xl m-0"><?= e($botName) ?></h1>
                    <p class="text-muted mb-0"><?= et('bots.detail_title') ?></p>
                </div>
            </div>
            <div class="cluster gap-2">
                <span class="badge<?= (string) ($bot['status'] ?? '') === 'active' ? ' badge-primary' : '' ?>"><?= e((string) ($bot['status'] ?? '')) ?></span>
                <a class="btn btn-secondary btn-sm" href="<?= e($profileUrl) ?>" target="_blank" rel="noopener"><?= icon('external-link') ?> <span><?= et('bots.detail_public_profile') ?></span></a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid sm:grid-2 md:grid-4">
                <?php foreach ($stats as $stat): ?>
                    <div class="card">
                        <div class="card-body stack gap-1">
                            <span class="table-meta"><?= et((string) $stat['label']) ?></span>
                            <strong class="text-2xl"><?= e((string) $stat['value']) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('settings') ?> <?= et('bots.detail_account') ?></h2></div>
        <form method="post" action="/admin/bots/<?= e((int) ($bot['id'] ?? 0)) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_bot">
            <div class="card-body grid md:grid-2">
                <label class="field"><span class="label"><?= et('common.status') ?></span><select class="select" name="status">
                    <?php foreach (['active' => 'users.statuses.active', 'waiting' => 'users.statuses.waiting', 'ban' => 'users.statuses.ban'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string) ($bot['status'] ?? '') === $value ? ' selected' : '' ?>><?= et($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <div class="field"><span class="label"><?= et('bots.detail_identity') ?></span><div class="table-meta">@<?= e((string) ($bot['username'] ?? '')) ?> · <?= et('users.roles.bot') ?></div></div>
                <label class="field settings-field-span"><span class="label"><?= et('bots.detail_bio') ?></span><textarea class="textarea" name="bio" rows="4" maxlength="500"><?= e((string) ($bot['bio'] ?? '')) ?></textarea></label>
                <div class="field settings-field-span">
                    <span class="label"><?= et('account.avatar') ?></span>
                    <div class="cluster gap-3">
                        <div class="avatar avatar-lg"><?= user_avatar_html($bot, '@' . (string) ($bot['username'] ?? '')) ?></div>
                        <div class="stack gap-1">
                            <input class="input" type="file" name="avatar" accept="image/png,image/jpeg,image/webp">
                            <?php if (user_avatar_url($bot) !== ''): ?>
                                <label class="check"><input type="checkbox" name="remove_avatar" value="1"> <span><?= et('account.remove_avatar') ?></span></label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer cluster justify-end"><button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button></div>
        </form>
    </section>

    <section class="grid lg:grid-2">
        <article class="card">
            <div class="card-header split">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('link') ?> <?= et('bots.detail_sources') ?></h2>
            </div>
            <div class="card-body stack">
                <?php if ($sources === []): ?>
                    <p class="text-muted mb-0"><?= et('bots.no_sources') ?></p>
                <?php else: ?>
                    <?php foreach ($sources as $source): ?>
                        <?php $sourceId = (int) ($source['id'] ?? 0); $enabled = (bool) ($source['enabled'] ?? false); ?>
                        <details class="result-item">
                            <summary class="split gap-3">
                                <div class="stack gap-1">
                                    <strong><?= e((string) ($source['name'] ?? '')) ?></strong>
                                    <span class="table-meta"><?= e((string) ($source['feed_url'] ?? '')) ?></span>
                                    <small class="table-meta"><?= et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?><?php if (!empty($source['last_imported_at'])): ?> · <?= et('bots.last_imported', ['time' => datetime((string) $source['last_imported_at'])]) ?><?php endif; ?></small>
                                </div>
                                <span class="badge<?= $enabled ? ' badge-primary' : '' ?>"><?= et($enabled ? 'bots.enabled' : 'bots.disabled') ?></span>
                            </summary>
                            <div class="stack gap-2 mt-3">
                                <div class="grid sm:grid-2">
                                    <div><span class="label"><?= et('bots.source_name') ?></span><div><?= e((string) ($source['name'] ?? '')) ?></div></div>
                                    <div><span class="label"><?= et('bots.interval') ?></span><div><?= et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?></div></div>
                                </div>
                                <div><span class="label"><?= et('bots.feed_url') ?></span><a class="text-muted" href="<?= e((string) ($source['feed_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) ($source['feed_url'] ?? '')) ?></a></div>
                                <div><span class="label"><?= et('bots.template') ?></span><pre class="code-block"><code><?= e((string) ($source['post_template'] ?? '')) ?></code></pre></div>
                                <?php if (!empty($source['last_error'])): ?><div class="text-danger"><?= e((string) $source['last_error']) ?></div><?php endif; ?>
                                <div class="cluster gap-2">
                                    <form method="post">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_source"><input type="hidden" name="source_id" value="<?= e($sourceId) ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit"><?= icon($enabled ? 'minus' : 'play') ?> <span><?= et($enabled ? 'bots.detail_pause' : 'bots.detail_enable') ?></span></button>
                                    </form>
                                    <?php if ($enabled): ?>
                                        <form method="post">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="run_source"><input type="hidden" name="source_id" value="<?= e($sourceId) ?>">
                                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('refresh') ?> <span><?= et('bots.detail_run_now') ?></span></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('refresh') ?> <?= et('bots.detail_last_run') ?></h2></div>
            <div class="card-body stack">
                <?php if ($lastRun === null): ?>
                    <p class="text-muted mb-0"><?= et('bots.detail_no_runs') ?></p>
                <?php else: ?>
                    <div class="split"><span><?= et('bots.detail_source') ?></span><strong><?= e((string) ($lastRun['source_name'] ?? '')) ?></strong></div>
                    <div class="split"><span><?= et('bots.detail_status') ?></span><span class="badge<?= (string) ($lastRun['status'] ?? '') === 'error' ? '' : ' badge-primary' ?>"><?= e((string) ($lastRun['status'] ?? '')) ?></span></div>
                    <div class="split"><span><?= et('bots.detail_started') ?></span><time datetime="<?= e(date_iso((string) ($lastRun['started_at'] ?? ''))) ?>"><?= e(datetime((string) ($lastRun['started_at'] ?? ''))) ?></time></div>
                    <div class="split"><span><?= et('bots.detail_items_seen') ?></span><strong><?= e((int) ($lastRun['items_seen'] ?? 0)) ?></strong></div>
                    <?php if (!empty($lastRun['error'])): ?><div class="alert alert-danger mb-0"><?= e((string) $lastRun['error']) ?></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="grid lg:grid-2">
        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('clock') ?> <?= et('bots.detail_history') ?></h2></div>
            <div class="card-body">
                <?php if ($runs === []): ?>
                    <p class="text-muted mb-0"><?= et('bots.detail_no_runs') ?></p>
                <?php else: ?>
                    <div class="table-wrap"><table class="table"><thead><tr><th><?= et('bots.detail_source') ?></th><th><?= et('bots.detail_status') ?></th><th><?= et('bots.detail_started') ?></th><th><?= et('bots.detail_items_seen') ?></th></tr></thead><tbody>
                    <?php foreach ($runs as $run): ?>
                        <tr><td><?= e((string) ($run['source_name'] ?? '')) ?></td><td><?= e((string) ($run['status'] ?? '')) ?></td><td><?= e(datetime((string) ($run['started_at'] ?? ''))) ?></td><td><?= e((int) ($run['items_seen'] ?? 0)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('file') ?> <?= et('bots.detail_posts') ?></h2></div>
            <div class="card-body stack">
                <?php if ($lastPosts === []): ?>
                    <p class="text-muted mb-0"><?= et('bots.detail_no_posts') ?></p>
                <?php else: ?>
                    <?php foreach ($lastPosts as $post): ?>
                        <a class="result-item" href="<?= e(status_url((int) ($post['id'] ?? 0))) ?>"><strong><?= e(plain_text_limit((string) ($post['body'] ?? ''), 180)) ?></strong><small class="table-meta"><?= e(datetime((string) ($post['published_at'] ?? ''))) ?></small></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});

function tc_admin_bot_detail_runs(int $botId, int $limit = 30): array
{
    return all(
        'SELECT br.*, bs.name AS source_name
         FROM bot_source_runs br
         INNER JOIN bot_sources bs ON bs.id = br.source_id
         WHERE br.bot_user_id = ?
         ORDER BY br.started_at DESC, br.id DESC
         LIMIT ' . max(1, min(100, $limit)),
        [$botId]
    );
}

function tc_admin_bot_detail_stats(int $botId, array $sources, array $runs): array
{
    return [
        ['label' => 'bots.detail_stat_sources', 'value' => count($sources)],
        ['label' => 'bots.detail_stat_active_sources', 'value' => count(array_filter($sources, static fn (array $source): bool => (bool) ($source['enabled'] ?? false)))],
        ['label' => 'bots.detail_stat_posts', 'value' => moderation_user_post_count($botId)],
        ['label' => 'bots.detail_stat_runs', 'value' => count($runs)],
    ];
}
