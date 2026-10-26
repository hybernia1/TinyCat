<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();

if (\TinyCat\Http\Request::current()->method() !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

$cronToken = \TinyCat\Scheduler\Tokens::cron_token(true);
$cronUrl = \TinyCat\Site\Presentation::absolute_url('/scheduled-tasks.php');
$runnerPath = \TinyCat\Support\Paths::basePath('scheduled-tasks.php');
$taskViews = [];

foreach (\TinyCat\Bots\Importer::scheduledTasks() as $task => $definition) {
    $admin = $definition['admin'];

    $arguments = [];
    foreach ((array) $definition['options'] as $name => $default) {
        $arguments[] = '--' . str_replace('_', '-', (string) $name) . '=' . (int) $default;
    }

    $taskViews[$task] = [
        ...$admin,
        'cli_arguments' => implode(' ', $arguments),
    ];
}

$taskViews['cleanup'] = [
        'icon' => 'database',
        'title' => 'cron.tasks.cleanup',
        'help' => 'cron.tasks.cleanup_help',
        'schedule' => 'cron.tasks.cleanup_schedule',
        'cli_arguments' => '--cleanup-batch=500',
];

foreach ($taskViews as $task => &$view) {
    $taskUrl = $cronUrl . '?task=' . rawurlencode($task);
    $view['query_url'] = $taskUrl . '&bearer=' . rawurlencode($cronToken);
    $view['http_command'] = 'curl -fsS -X POST -H "Authorization: Bearer ' . $cronToken . '" "' . $taskUrl . '"';
    $view['cli_command'] = 'php "' . $runnerPath . '" --task=' . $task . ' ' . (string) $view['cli_arguments'];
}
unset($view);

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('cron.title'),
    'current' => '/admin/cron',
], static function () use ($cronToken, $cronUrl, $taskViews): void {
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
                        <label class="field"><span class="label"><?= \TinyCat\Support\Html::et('cron.url') ?></span><input class="input" value="<?= \TinyCat\Support\Html::e($cronUrl) ?>" data-scheduler-url readonly></label>
                        <label class="field"><span class="label"><?= \TinyCat\Support\Html::et('cron.token') ?></span><input class="input" value="<?= \TinyCat\Support\Html::e($cronToken) ?>" data-scheduler-token readonly></label>
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
                            <label class="field"><span class="label"><?= \TinyCat\Support\Html::et('cron.task_url') ?></span><input class="input" value="<?= \TinyCat\Support\Html::e((string) $view['query_url']) ?>" data-scheduler-url readonly><span class="help"><?= \TinyCat\Support\Html::et('cron.query_help') ?></span></label>
                            <div class="field"><span class="label"><?= \TinyCat\Support\Html::et('cron.http_command') ?></span><pre class="code-block"><code data-scheduler-command><?= \TinyCat\Support\Html::e((string) $view['http_command']) ?></code></pre></div>
                            <div class="field"><span class="label"><?= \TinyCat\Support\Html::et('cron.cli_command') ?></span><pre class="code-block"><code data-scheduler-command><?= \TinyCat\Support\Html::e((string) $view['cli_command']) ?></code></pre></div>
                            <p class="help m-0"><?= \TinyCat\Support\Html::et((string) $view['schedule']) ?></p>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
});
