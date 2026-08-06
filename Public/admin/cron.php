<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

$cronApi = route_path() === '/api/admin/cron-token';

if ($cronApi) {
    csrf_require();
    cron_token_rotate();
    flash('success', t('cron.messages.token_rotated'));
    api_ok([
        'rotated' => true,
        'redirect' => '/admin/cron',
    ], t('cron.messages.token_rotated'));
}

if (method() !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

$cronToken = cron_token(true);
$cronUrl = absolute_url('/scheduled-tasks.php');
$runnerPath = base_path('scheduled-tasks.php');
$taskViews = [];

foreach (Registry::scheduledTasks() as $task => $definition) {
    $admin = $definition['admin'] ?? null;
    if (!is_array($admin)) {
        continue;
    }

    $arguments = [];
    foreach ((array) ($definition['options'] ?? []) as $name => $default) {
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

layout('layout', [
    'title' => t('cron.title'),
    'current' => '/admin/cron',
], static function () use ($cronToken, $cronUrl, $taskViews): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <div>
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('clock') ?> <?= et('cron.title') ?></h1>
                <p class="text-muted mb-0"><?= et('cron.intro') ?></p>
            </div>
            <form method="post" action="/api/admin/cron-token" data-ajax-form data-confirm="<?= et('cron.rotate_confirm') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-secondary btn-sm" type="submit"><?= icon('refresh') ?> <span><?= et('cron.rotate') ?></span></button>
            </form>
        </div>
        <div class="card-body stack">
            <div class="disclosure-list">
                <details class="disclosure-item" name="scheduled-task">
                    <summary class="disclosure-summary">
                        <span class="disclosure-heading">
                            <?= icon('key') ?>
                            <span class="stack stack-gap-4">
                                <strong><?= et('cron.access_title') ?></strong>
                                <small><?= et('cron.access_help') ?></small>
                            </span>
                        </span>
                        <?= icon('chevron-down', 'icon disclosure-chevron') ?>
                    </summary>
                    <div class="disclosure-body stack">
                        <label class="field"><span class="label"><?= et('cron.url') ?></span><input class="input" value="<?= e($cronUrl) ?>" readonly></label>
                        <label class="field"><span class="label"><?= et('cron.token') ?></span><input class="input" value="<?= e($cronToken) ?>" readonly></label>
                    </div>
                </details>

                <?php foreach ($taskViews as $view): ?>
                    <details class="disclosure-item" name="scheduled-task">
                        <summary class="disclosure-summary">
                            <span class="disclosure-heading">
                                <?= icon((string) $view['icon']) ?>
                                <span class="stack stack-gap-4">
                                    <strong><?= et((string) $view['title']) ?></strong>
                                    <small><?= et((string) $view['help']) ?></small>
                                </span>
                            </span>
                            <?= icon('chevron-down', 'icon disclosure-chevron') ?>
                        </summary>
                        <div class="disclosure-body stack">
                            <label class="field"><span class="label"><?= et('cron.task_url') ?></span><input class="input" value="<?= e((string) $view['query_url']) ?>" readonly><span class="help"><?= et('cron.query_help') ?></span></label>
                            <div class="field"><span class="label"><?= et('cron.http_command') ?></span><pre class="code-block"><code><?= e((string) $view['http_command']) ?></code></pre></div>
                            <div class="field"><span class="label"><?= et('cron.cli_command') ?></span><pre class="code-block"><code><?= e((string) $view['cli_command']) ?></code></pre></div>
                            <p class="help m-0"><?= et((string) $view['schedule']) ?></p>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
});
