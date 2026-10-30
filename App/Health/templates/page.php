<?php
declare(strict_types=1);
/** @var array<string, mixed> $snapshot */
/** @var list<array<string, mixed>> $checks */
/** @var list<array<string, mixed>> $issues */
/** @var array<string, mixed>|null $http */
/** @var int $passed */
use TinyCat\Support\Html;
use TinyCat\Support\Dates;
use TinyCat\Support\Components;
use TinyCat\Support\Localization;
?>
<div class="stack stack-gap-16 min-w-0">
<div class="stack">
        <div class="split">
            <div class="stack stack-gap-8">
                <div class="cluster"><?= Components::render('ui/status-badge', ['tone' => $snapshot['status'], 'label' => Localization::t('health.status.' . $snapshot['status'])]) ?></div>
                <div class="cluster gap-3 table-meta">
                    <?php if ($checks !== []): ?><span><?= Html::et('health.overview_count', ['passed' => $passed, 'total' => count($checks)]) ?></span><?php endif; ?>
                    <?php if (is_array($snapshot['report'])): ?><span><?= Html::et('health.checked_at') ?> <time datetime="<?= Html::e($snapshot['report']['checked_at']) ?>"><?= Html::e(Dates::dateTime($snapshot['report']['checked_at'])) ?></time></span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($snapshot['latest_failed']): ?><p role="alert" class="m-0"><?= Html::et('health.last_failed') ?></p><?php endif; ?>
        <?php if ($snapshot['stale']): ?><p role="status" class="help m-0"><?= Html::et('health.stale') ?></p><?php endif; ?>
        <?php if ($http !== null): $metrics = $http['metrics'] ?? []; ?>
            <div class="grid sm:grid-2 md:grid-3">
                <?php foreach ([['clock', 'TTFB', $metrics['ttfb_ms'] ?? null, 'ms'], ['refresh', Localization::t('health.http.total'), $metrics['total_ms'] ?? null, 'ms'], ['globe', 'HTTP', !empty($metrics['http_status']) ? $metrics['http_status'] : null, '']] as [$icon, $label, $value, $unit]): ?>
                    <?= Components::render('ui/stat-card', ['icon' => $icon, 'label' => $label, 'value' => $value === null ? '-' : (string) round((float) $value, 1), 'unit' => $unit]) ?>
                <?php endforeach; ?>
            </div>
        <?php elseif (!is_array($snapshot['report'])): ?><?= Components::render('ui/empty-state', ['label' => Localization::t('health.empty'), 'icon' => 'shield']) ?><?php endif; ?>
</div>
<?php if ($issues !== []): ?>
<section class="card">
    <div class="card-header"><h2 class="text-lg m-0"><?= Html::et('health.status.warning') ?> <span class="text-muted">(<?= count($issues) ?>)</span></h2></div>
    <div class="card-body stack">
        <?php foreach ($issues as $check): ?>
            <?= Components::render('ui/notice', ['tone' => $check['status'], 'title' => Localization::t('health.checks.' . $check['id']), 'description' => Localization::t('health.advice.' . $check['id']), 'detail' => $check['id'] === 'http' && !empty($check['metrics']['error']) ? Localization::t('health.http.errors.' . $check['metrics']['error']) : '']) ?>
            <?php if ($check['id'] === 'scheduler'): ?><?= Components::render('health/scheduler-findings', ['metrics' => $check['metrics'] ?? []]) ?><?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php if ($checks !== []): ?>
<details class="disclosure-item">
    <summary class="disclosure-summary"><span><?= Html::et('health.all_checks') ?> <span class="text-muted">(<?= count($checks) ?>)</span></span><?= Html::icon('chevron-down', 'icon disclosure-chevron') ?></summary>
    <div class="card-body"><ul class="result-list">
        <?php foreach ($checks as $check): ?>
            <li class="result-item split"><span><?= Html::et('health.checks.' . $check['id']) ?></span><span class="cluster gap-3"><span class="table-meta"><?= Html::e($check['duration_ms']) ?> ms</span><?= Components::render('ui/status-badge', ['tone' => $check['status'], 'label' => Localization::t('health.status.' . $check['status'])]) ?></span></li>
        <?php endforeach; ?>
    </ul></div>
</details>
<?php endif; ?>
<section class="card">
    <div class="card-header"><h2 class="text-lg m-0"><?= Html::et('health.history') ?></h2></div>
    <div class="card-body">
    <?php if ($snapshot['history'] === []): ?><?= Components::render('ui/empty-state', ['label' => Localization::t('health.empty'), 'icon' => 'clock']) ?><?php else: ?>
    <div class="table-wrap min-w-0"><table class="table">
        <thead><tr><th><?= Html::et('health.checked_at') ?></th><th><?= Html::et('health.result') ?></th><th>TTFB</th><th><?= Html::et('health.http.total') ?></th><th>HTTP</th><th class="table-actions-cell"><?= Html::et('common.actions') ?></th></tr></thead>
        <tbody><?php foreach ($snapshot['history'] as $run): $timings = $run['http']['metrics'] ?? []; ?>
            <tr>
                <td><div class="table-identity"><div class="table-identity-body"><time class="table-primary" datetime="<?= Html::e($run['started_at']) ?>"><?= Html::e(Dates::dateTime($run['started_at'])) ?></time></div></div></td>
                <td><?= Components::render('ui/status-badge', ['tone' => $run['health_status'], 'label' => Localization::t('health.status.' . $run['health_status'])]) ?><?php if ($run['status'] !== 'completed'): ?><br><small class="table-meta"><?= Html::et('cron.statuses.' . $run['status']) ?></small><?php endif; ?></td>
                <td class="table-meta"><?= isset($timings['ttfb_ms']) ? Html::e(round((float) $timings['ttfb_ms'], 1)) . ' ms' : '&mdash;' ?></td>
                <td class="table-meta"><?= isset($timings['total_ms']) ? Html::e(round((float) $timings['total_ms'], 1)) . ' ms' : '&mdash;' ?></td>
                <td><?= !empty($timings['http_status']) ? Html::e($timings['http_status']) : '&mdash;' ?></td>
                <td class="table-actions-cell"><div class="table-actions"><?= Components::render('ui/modal-trigger', ['class' => 'btn btn-sm btn-ghost btn-icon', 'modal' => 'health-run-' . $run['id'], 'url' => '/api/scheduler/runs/' . $run['id'], 'label' => Localization::t('health.details'), 'icon' => 'eye', 'icon_only' => true]) ?></div></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    </div>
</section>

</div>
