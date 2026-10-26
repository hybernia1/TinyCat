<?php
declare(strict_types=1);
/** @var string $avatarUrl */
/** @var int $botId */
/** @var array<mixed> $bot */
/** @var array<mixed> $sources */
/** @var array<mixed> $runs */
/** @var array<mixed> $stats */
/** @var array<mixed> $lastPosts */
/** @var array<mixed>|null $lastRun */
/** @var string $profileUrl */
/** @var array<string, string> $statuses */
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
            <?= \TinyCat\Support\Components::render('ui/account-status', ['status' => (string) ($bot['status'] ?? ''), 'label' => (string) ($statuses[$bot['status'] ?? ''] ?? $bot['status'] ?? '')]) ?>
            <a class="btn btn-secondary btn-sm" href="<?= \TinyCat\Support\Html::e($profileUrl) ?>" target="_blank" rel="noopener"><?= \TinyCat\Support\Html::icon('external-link') ?> <span><?= \TinyCat\Support\Html::et('bots.detail_public_profile') ?></span></a>
        </div>
    </div>
    <div class="card-body">
        <div class="grid sm:grid-2 md:grid-4">
            <?php foreach ($stats as $stat): ?>
                <div class="card">
                    <div class="card-body stack gap-1">
                        <span class="table-meta"><?= \TinyCat\Support\Html::et((string) $stat['label']) ?></span>
                        <strong class="text-2xl" data-bot-stat="<?= \TinyCat\Support\Html::e((string) $stat['key']) ?>"><?= \TinyCat\Support\Html::e((string) $stat['value']) ?></strong>
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
                <?php foreach ($statuses as $value => $label): ?>
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
                        <?php if ($avatarUrl !== ''): ?>
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
        <div class="card-body stack" id="bots-list" data-bot-sources>
            <?= \TinyCat\Bots\Views::sources(['sources' => $sources, 'bots' => [$bot], 'filter_bot_id' => $botId]) ?>
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
                    <a class="result-item" href="<?= \TinyCat\Support\Html::e($post['url']) ?>"><strong><?= \TinyCat\Support\Html::e(\TinyCat\Support\Text::plain_text_limit((string) ($post['body'] ?? ''), 180)) ?></strong><small class="table-meta"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::displayDateTime((string) ($post['published_at'] ?? ''))) ?></small></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </article>
</section>
