<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();

    $tasks = maintenance_cleanup_tasks();
    $batchSize = maintenance_cleanup_batch_size(post('batch_size', null));
    $selected = array_values(array_filter(
        array_map(static fn (mixed $task): string => trim((string) $task), (array) post('tasks', [])),
        static fn (string $task): bool => isset($tasks[$task])
    ));

    if ($selected === []) {
        flash('warning', t('maintenance.messages.nothing_selected'));
        redirect('/admin/maintenance');
    }

    $results = maintenance_cleanup_run($selected, $batchSize);
    $changed = array_sum(array_map(
        static fn (array $result): int => (int) ($result['changed'] ?? 0),
        $results
    ));
    $remaining = array_sum(array_map(
        static fn (array $result): int => max(0, (int) ($result['remaining'] ?? 0)),
        $results
    ));
    $errors = array_filter($results, static fn (array $result): bool => isset($result['error']));
    $messageKey = $errors === []
        ? ($remaining > 0 ? 'maintenance.messages.batch_done' : 'maintenance.messages.done')
        : 'maintenance.messages.done_with_errors';

    flash('maintenance_results', $results);
    flash('maintenance_batch_size', $batchSize);
    flash($errors === [] && $remaining < 1 ? 'success' : 'warning', t($messageKey, [
        'count' => (string) $changed,
        'remaining' => (string) $remaining,
    ]));

    redirect('/admin/maintenance');
}

layout('layout', [
    'title' => t('maintenance.title'),
    'current' => '/admin/maintenance',
], static function (): void {
    $tasks = maintenance_cleanup_tasks();
    $results = flash('maintenance_results');
    $results = is_array($results) ? $results : [];
    $batchSize = maintenance_cleanup_batch_size(flash('maintenance_batch_size'));
    $remaining = array_sum(array_map(
        static fn (array $result): int => max(0, (int) ($result['remaining'] ?? 0)),
        $results
    ));
    $selectedTasks = array_values(array_intersect(array_keys($tasks), array_keys($results)));
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('database') ?> <?= et('maintenance.title') ?></h2>
        </div>
        <form method="post" action="/admin/maintenance">
            <?= csrf_field() ?>
            <div class="card-body stack">
                <p class="text-muted m-0"><?= et('maintenance.intro') ?></p>

                <label class="field">
                    <span class="label"><?= et('maintenance.batch_size') ?></span>
                    <select class="select" name="batch_size">
                        <?php foreach ([500, 1000, 2500, 5000] as $size): ?>
                            <option value="<?= e((string) $size) ?>"<?= $batchSize === $size ? ' selected' : '' ?>><?= e(number_format($size, 0, '.', ' ')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="grid md:grid-2">
                    <?php foreach ($tasks as $key => $task): ?>
                        <label class="check-card">
                            <input type="checkbox" name="tasks[]" value="<?= e($key) ?>" checked>
                            <span class="check-card-body">
                                <strong class="cluster gap-2">
                                    <?= icon((string) ($task['icon'] ?? 'database')) ?>
                                    <?= e((string) ($task['label'] ?? $key)) ?>
                                </strong>
                                <small><?= e((string) ($task['description'] ?? '')) ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer cluster justify-end">
                <button class="btn btn-primary" type="submit">
                    <?= icon('database') ?> <span><?= et('maintenance.run') ?></span>
                </button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('shield') ?> <?= et('maintenance.production_title') ?></h2>
        </div>
        <div class="card-body stack">
            <p class="text-muted m-0"><?= et('maintenance.production_intro') ?></p>
            <ul class="result-list">
                <li class="result-item"><?= icon('check') ?> <span><?= et('maintenance.production_normalize') ?></span></li>
                <li class="result-item"><?= icon('check') ?> <span><?= et('maintenance.production_repeatable') ?></span></li>
                <li class="result-item"><?= icon('check') ?> <span><?= et('maintenance.production_no_ip') ?></span></li>
                <li class="result-item"><?= icon('check') ?> <span><?= et('maintenance.production_batched') ?></span></li>
            </ul>
        </div>
    </section>

    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('check-circle') ?> <?= et('maintenance.results') ?></h2>
            <?php if ($remaining > 0 && $selectedTasks !== []): ?>
                <form method="post" action="/admin/maintenance">
                    <?= csrf_field() ?>
                    <input type="hidden" name="batch_size" value="<?= e((string) $batchSize) ?>">
                    <?php foreach ($selectedTasks as $task): ?>
                        <input type="hidden" name="tasks[]" value="<?= e($task) ?>">
                    <?php endforeach; ?>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('database') ?> <span><?= et('maintenance.run_next_batch') ?></span></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($results === []): ?>
                <div class="alert alert-info"><?= et('maintenance.no_results') ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?= et('maintenance.task') ?></th>
                                <th><?= et('maintenance.before') ?></th>
                                <th><?= et('maintenance.changed') ?></th>
                                <th><?= et('maintenance.remaining') ?></th>
                                <th><?= et('maintenance.details') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $key => $result): ?>
                                <?php
                                $task = $tasks[$key] ?? [];
                                $hasError = isset($result['error']);
                                ?>
                                <tr>
                                    <td>
                                        <strong class="cluster gap-2">
                                            <?= icon((string) ($task['icon'] ?? 'database')) ?>
                                                <?= e((string) ($task['label'] ?? $key)) ?>
                                            </strong>
                                        </td>
                                        <td><?= e(tc_admin_maintenance_count($result['before'] ?? null)) ?></td>
                                        <td>
                                            <span class="badge<?= $hasError ? ' badge-danger' : ' badge-primary' ?>">
                                                <?= e(tc_admin_maintenance_count((int) ($result['changed'] ?? 0))) ?>
                                            </span>
                                        </td>
                                        <td><?= e(tc_admin_maintenance_count($result['remaining'] ?? null)) ?></td>
                                        <td><?= tc_admin_maintenance_details($result) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
});

function tc_admin_maintenance_details(array $result): string
{
    $details = [];

    foreach ($result as $key => $value) {
        if (in_array($key, ['task', 'before', 'changed', 'remaining', 'done', 'batch_size'], true) || is_array($value) || is_object($value)) {
            continue;
        }

        $details[] = '<span class="badge">' . e(str_replace('_', ' ', (string) $key)) . ': ' . e((string) $value) . '</span>';
    }

    return $details === [] ? '<span class="text-muted">' . et('maintenance.no_details') . '</span>' : '<div class="cluster gap-2">' . implode('', $details) . '</div>';
}

function tc_admin_maintenance_count(mixed $value): string
{
    return $value === null ? t('maintenance.unknown') : number_format((int) $value, 0, '.', ' ');
}
