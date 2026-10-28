<?php
declare(strict_types=1);
/** @var string $cronToken */
/** @var string $cronUrl */
/** @var array<mixed> $taskViews */
?>
<section class="card">
    <div class="card-header split">
        <div>
            <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('clock') ?> <?= \TinyCat\Support\Html::et('cron.title') ?></h1>
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('cron.intro') ?></p>
        </div>
        <form method="post" action="/api/scheduler/tokens" data-ajax-form data-confirm="<?= \TinyCat\Support\Html::et('cron.rotate_confirm') ?>">
            <?= \TinyCat\Security\Csrf::field() ?>
            <button class="btn btn-secondary btn-sm" type="submit"><?= \TinyCat\Support\Html::icon('refresh') ?> <span><?= \TinyCat\Support\Html::et('cron.rotate') ?></span></button>
        </form>
    </div>
    <div class="card-body stack">
        <div class="disclosure-list">
            <details class="disclosure-item" name="scheduled-task">
                <summary class="disclosure-summary">
                    <span class="disclosure-heading">
                        <?= \TinyCat\Support\Html::icon('key') ?>
                        <span class="stack stack-gap-4">
                            <strong><?= \TinyCat\Support\Html::et('cron.access_title') ?></strong>
                            <small><?= \TinyCat\Support\Html::et('cron.access_help') ?></small>
                        </span>
                    </span>
                    <?= \TinyCat\Support\Html::icon('chevron-down', 'icon disclosure-chevron') ?>
                </summary>
                <div class="disclosure-body stack">
                    <label class="field"><span class="label cluster gap-2"><?= \TinyCat\Support\Html::icon('globe') ?> <?= \TinyCat\Support\Html::et('cron.url') ?></span><input class="input" value="<?= \TinyCat\Support\Html::e($cronUrl) ?>" data-scheduler-url readonly></label>
                    <label class="field"><span class="label cluster gap-2"><?= \TinyCat\Support\Html::icon('key') ?> <?= \TinyCat\Support\Html::et('cron.token') ?></span><input class="input" value="<?= \TinyCat\Support\Html::e($cronToken) ?>" data-scheduler-token readonly></label>
                </div>
            </details>

            <?php foreach ($taskViews as $view): ?>
                <details class="disclosure-item" name="scheduled-task">
                    <summary class="disclosure-summary">
                        <span class="disclosure-heading">
                            <?= \TinyCat\Support\Html::icon((string) $view['icon']) ?>
                            <span class="stack stack-gap-4">
                                <strong><?= \TinyCat\Support\Html::et((string) $view['title']) ?></strong>
                                <small><?= \TinyCat\Support\Html::et((string) $view['help']) ?></small>
                            </span>
                        </span>
                        <?= \TinyCat\Support\Html::icon('chevron-down', 'icon disclosure-chevron') ?>
                    </summary>
                    <div class="disclosure-body stack">
                        <label class="field"><span class="label cluster gap-2"><?= \TinyCat\Support\Html::icon('link') ?> <?= \TinyCat\Support\Html::et('cron.task_url') ?></span><input class="input" value="<?= \TinyCat\Support\Html::e((string) $view['query_url']) ?>" data-scheduler-url readonly></label>
                        <div class="field"><span class="label cluster gap-2"><?= \TinyCat\Support\Html::icon('globe') ?> <?= \TinyCat\Support\Html::et('cron.http_command') ?></span><pre class="code-block"><code data-scheduler-command><?= \TinyCat\Support\Html::e((string) $view['http_command']) ?></code></pre></div>
                        <div class="field"><span class="label cluster gap-2"><?= \TinyCat\Support\Html::icon('code') ?> <?= \TinyCat\Support\Html::et('cron.cli_command') ?></span><pre class="code-block"><code data-scheduler-command><?= \TinyCat\Support\Html::e((string) $view['cli_command']) ?></code></pre></div>
                        <p class="help m-0"><?= \TinyCat\Support\Html::et((string) $view['schedule']) ?></p>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
