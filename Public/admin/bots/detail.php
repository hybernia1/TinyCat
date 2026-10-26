<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();
$botId = max(0, (int) \TinyCat\Http\Web::get('id', 0));
$detail = \TinyCat\Bots\Administration::accountDetailData($botId);

if ($detail === null) {
    http_response_code(404);
    \TinyCat\Support\View::layout('layout', [
        'title' => \TinyCat\Support\Localization::t('bots.detail_not_found'),
        'current' => '/admin/bots/accounts',
    ], static function (): void {
        ?><div class="alert alert-info"><?= \TinyCat\Support\Html::et('bots.detail_not_found') ?></div><?php
    });
    return;
}

$bot = (array) $detail['account'];
$sources = (array) $detail['sources'];
$runs = (array) $detail['runs'];
$stats = (array) $detail['stats'];
$lastPosts = (array) $detail['last_posts'];
$lastRun = $detail['last_run'];
$profileUrl = (string) $detail['profile_url'];

\TinyCat\Support\View::layout('layout', [
    'title' => '@' . (string) $bot['username'],
    'current' => '/admin/bots/accounts',
    'actions' => '<a class="btn btn-secondary btn-sm" href="/admin/bots/accounts">' . \TinyCat\Support\Html::icon('arrow-left') . ' <span>' . \TinyCat\Support\Html::et('common.back') . '</span></a>',
], static function () use ($botId, $bot, $sources, $runs, $stats, $lastPosts, $lastRun, $profileUrl): void {
    $botName = '@' . (string) ($bot['username'] ?? '');
    ?>
    <section data-bot-detail-id="<?= \TinyCat\Support\Html::e($botId) ?>" class="card">
        <div class="card-header split">
            <div class="cluster gap-3">
                <div class="avatar avatar-lg"><?= \TinyCat\Users\Views::avatar(['user' => $bot, 'alt' => $botName]) ?></div>
                <div>
                    <h1 class="text-xl m-0"><?= \TinyCat\Support\Html::e($botName) ?></h1>
                    <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('bots.detail_title') ?></p>
                </div>
            </div>
            <div class="cluster gap-2">
                <span class="badge<?= (string) ($bot['status'] ?? '') === 'active' ? ' badge-primary' : '' ?>"><?= \TinyCat\Support\Html::e((string) ($bot['status'] ?? '')) ?></span>
                <a class="btn btn-secondary btn-sm" href="<?= \TinyCat\Support\Html::e($profileUrl) ?>" target="_blank" rel="noopener"><?= \TinyCat\Support\Html::icon('external-link') ?> <span><?= \TinyCat\Support\Html::et('bots.detail_public_profile') ?></span></a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid sm:grid-2 md:grid-4">
                <?php foreach ($stats as $stat): ?>
                    <div class="card">
                        <div class="card-body stack gap-1">
                            <span class="table-meta"><?= \TinyCat\Support\Html::et((string) $stat['label']) ?></span>
                            <strong class="text-2xl"><?= \TinyCat\Support\Html::e((string) $stat['value']) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('settings') ?> <?= \TinyCat\Support\Html::et('bots.detail_account') ?></h2></div>
        <form method="post" action="/api/bots/accounts/<?= \TinyCat\Support\Html::e((int) ($bot['id'] ?? 0)) ?>" enctype="multipart/form-data" data-api-method="PATCH" data-ajax-form>
            <?= \TinyCat\Security\Csrf::field() ?><input type="hidden" name="_method" value="PATCH">
            <div class="card-body grid md:grid-2">
                <label class="field"><span class="label"><?= \TinyCat\Support\Html::et('common.status') ?></span><select class="select" name="status">
                    <?php foreach (\TinyCat\Support\AdminViews::admin_user_statuses() as $value => $label): ?>
                        <option value="<?= \TinyCat\Support\Html::e($value) ?>"<?= (string) ($bot['status'] ?? '') === $value ? ' selected' : '' ?>><?= \TinyCat\Support\Html::e($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <div class="field"><span class="label"><?= \TinyCat\Support\Html::et('bots.detail_identity') ?></span><div class="table-meta">@<?= \TinyCat\Support\Html::e((string) ($bot['username'] ?? '')) ?> · <?= \TinyCat\Support\Html::et('users.roles.bot') ?></div></div>
                <label class="field settings-field-span"><span class="label"><?= \TinyCat\Support\Html::et('bots.detail_bio') ?></span><textarea class="textarea" name="bio" rows="4" maxlength="500"><?= \TinyCat\Support\Html::e((string) ($bot['bio'] ?? '')) ?></textarea></label>
                <div class="field settings-field-span">
                    <span class="label"><?= \TinyCat\Support\Html::et('account.avatar') ?></span>
                    <div class="cluster gap-3">
                        <div class="avatar avatar-lg"><?= \TinyCat\Users\Views::avatar(['user' => $bot, 'alt' => '@' . (string) ($bot['username'] ?? '')]) ?></div>
                        <div class="stack gap-1">
                            <input class="input" type="file" name="avatar" accept="image/png,image/jpeg,image/webp">
                            <?php if (\TinyCat\Users\Avatars::user_avatar_url($bot) !== ''): ?>
                                <label class="check"><input type="checkbox" name="remove_avatar" value="1"> <span><?= \TinyCat\Support\Html::et('account.remove_avatar') ?></span></label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer cluster justify-end"><button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('save') ?> <span><?= \TinyCat\Support\Html::et('common.save') ?></span></button></div>
        </form>
    </section>

    <section class="grid lg:grid-2">
        <article class="card">
            <div class="card-header split">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('link') ?> <?= \TinyCat\Support\Html::et('bots.detail_sources') ?></h2>
            </div>
            <div class="card-body stack" data-bot-sources>
                <?php if ($sources === []): ?>
                    <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('bots.no_sources') ?></p>
                <?php else: ?>
                    <?php foreach ($sources as $source): ?>
                        <?php $sourceId = (int) ($source['id'] ?? 0); $enabled = (bool) ($source['enabled'] ?? false); ?>
                        <details class="result-item">
                            <summary class="split gap-3">
                                <div class="stack gap-1">
                                    <strong><?= \TinyCat\Support\Html::e((string) ($source['name'] ?? '')) ?></strong>
                                    <span class="table-meta"><?= \TinyCat\Support\Html::e((string) ($source['feed_url'] ?? '')) ?></span>
                                    <small class="table-meta"><?= \TinyCat\Support\Html::et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?><?php if (!empty($source['last_imported_at'])): ?> · <?= \TinyCat\Support\Html::et('bots.last_imported', ['time' => \TinyCat\Support\Dates::displayDateTime((string) $source['last_imported_at'])]) ?><?php endif; ?></small>
                                </div>
                                <span class="badge<?= $enabled ? ' badge-primary' : '' ?>"><?= \TinyCat\Support\Html::et($enabled ? 'bots.enabled' : 'bots.disabled') ?></span>
                            </summary>
                            <div class="stack gap-2 mt-3">
                                <div class="grid sm:grid-2">
                                    <div><span class="label"><?= \TinyCat\Support\Html::et('bots.source_name') ?></span><div><?= \TinyCat\Support\Html::e((string) ($source['name'] ?? '')) ?></div></div>
                                    <div><span class="label"><?= \TinyCat\Support\Html::et('bots.interval') ?></span><div><?= \TinyCat\Support\Html::et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?></div></div>
                                </div>
                                <div><span class="label"><?= \TinyCat\Support\Html::et('bots.feed_url') ?></span><a class="text-muted" href="<?= \TinyCat\Support\Html::e((string) ($source['feed_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= \TinyCat\Support\Html::e((string) ($source['feed_url'] ?? '')) ?></a></div>
                                <div><span class="label"><?= \TinyCat\Support\Html::et('bots.template') ?></span><pre class="code-block"><code><?= \TinyCat\Support\Html::e((string) ($source['post_template'] ?? '')) ?></code></pre></div>
                                <?php if (!empty($source['last_error'])): ?><div class="text-danger"><?= \TinyCat\Support\Html::e((string) $source['last_error']) ?></div><?php endif; ?>
                                <div class="cluster gap-2">
                                    <form method="post" action="/api/bots/sources/<?= \TinyCat\Support\Html::e($sourceId) ?>" data-api-method="PATCH" data-ajax-form>
                                        <?= \TinyCat\Security\Csrf::field() ?><input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>"><input type="hidden" name="source_id" value="<?= \TinyCat\Support\Html::e($sourceId) ?>"><input type="hidden" name="bot_id" value="<?= \TinyCat\Support\Html::e($botId) ?>"><input type="hidden" name="redirect" value="/admin/bots/<?= \TinyCat\Support\Html::e($botId) ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit"><?= \TinyCat\Support\Html::icon($enabled ? 'minus' : 'play') ?> <span><?= \TinyCat\Support\Html::et($enabled ? 'bots.detail_pause' : 'bots.detail_enable') ?></span></button>
                                    </form>
                                    <?php if ($enabled): ?>
                                        <form method="post" action="/api/bots/sources/<?= \TinyCat\Support\Html::e($sourceId) ?>/runs" data-ajax-form>
                                            <?= \TinyCat\Security\Csrf::field() ?><input type="hidden" name="source_id" value="<?= \TinyCat\Support\Html::e($sourceId) ?>"><input type="hidden" name="bot_id" value="<?= \TinyCat\Support\Html::e($botId) ?>"><input type="hidden" name="redirect" value="/admin/bots/<?= \TinyCat\Support\Html::e($botId) ?>">
                                            <button class="btn btn-primary btn-sm" type="submit"><?= \TinyCat\Support\Html::icon('refresh') ?> <span><?= \TinyCat\Support\Html::et('bots.detail_run_now') ?></span></button>
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
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('refresh') ?> <?= \TinyCat\Support\Html::et('bots.detail_last_run') ?></h2></div>
            <div class="card-body stack" data-bot-run-history data-bot-run-latest>
                <?= \TinyCat\Bots\Views::runs($lastRun !== null ? [$lastRun] : [], true) ?>
            </div>
        </article>
    </section>

    <section class="grid lg:grid-2">
        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('clock') ?> <?= \TinyCat\Support\Html::et('bots.detail_history') ?></h2></div>
            <div class="card-body" data-bot-run-history>
                <?= \TinyCat\Bots\Views::runs($runs) ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('file') ?> <?= \TinyCat\Support\Html::et('bots.detail_posts') ?></h2></div>
            <div class="card-body stack">
                <?php if ($lastPosts === []): ?>
                    <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('bots.detail_no_posts') ?></p>
                <?php else: ?>
                    <?php foreach ($lastPosts as $post): ?>
                        <a class="result-item" href="<?= \TinyCat\Support\Html::e(\TinyCat\Status\Posts::status_url((int) ($post['id'] ?? 0))) ?>"><strong><?= \TinyCat\Support\Html::e(\TinyCat\Support\Text::plain_text_limit((string) ($post['body'] ?? ''), 180)) ?></strong><small class="table-meta"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::displayDateTime((string) ($post['published_at'] ?? ''))) ?></small></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});
