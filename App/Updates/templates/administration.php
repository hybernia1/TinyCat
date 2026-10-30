<?php
declare(strict_types=1);
/** @var array<mixed>|null $release */
/** @var array<mixed> $result */
/** @var string $error */
/** @var array<mixed> $maintenance */
/** @var array<string, bool> $extensions */
/** @var bool $releaseCompatible */
/** @var bool $canInstall */
/** @var array<mixed> $history */
/** @var string $releaseNotesModal */
use TinyCat\Support\Components;
use TinyCat\Support\Localization;
use TinyCat\Support\Html;
$available = is_array($release) && !empty($release['available']);
?>
<div class="stack stack-gap-16 min-w-0">
<?php if ($maintenance !== []): ?>
    <section class="alert alert-warning stack">
        <strong class="cluster gap-2"><?= \TinyCat\Support\Html::icon('alert') ?> <?= \TinyCat\Support\Html::et('updates.maintenance_active') ?></strong>
        <span><?= \TinyCat\Support\Html::et('updates.maintenance_active_help', [
            'from' => (string) ($maintenance['from_version'] ?? \TinyCat\Support\Version::CURRENT),
            'to' => (string) ($maintenance['to_version'] ?? '?'),
        ]) ?></span>
        <form method="post" action="/api/updates/maintenance" data-api-method="DELETE" data-ajax-form data-confirm="<?= \TinyCat\Support\Html::et('updates.maintenance_disable_confirm') ?>" data-confirm-variant="danger">
            <?= \TinyCat\Security\Csrf::field() ?>
            <input type="hidden" name="_method" value="DELETE">
            <button class="btn btn-danger btn-sm" type="submit"><?= \TinyCat\Support\Html::icon('unlock') ?> <span><?= \TinyCat\Support\Html::et('updates.maintenance_disable') ?></span></button>
        </form>
    </section>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <section class="alert alert-danger stack">
        <strong><?= \TinyCat\Support\Html::et('updates.error_detail') ?></strong>
        <code><?= \TinyCat\Support\Html::e($error) ?></code>
    </section>
<?php endif; ?>

<div class="stack">
        <div class="cluster"><?= Components::render('ui/status-badge', ['tone' => !$canInstall ? 'warning' : (is_array($release) ? ($available ? 'primary' : 'ok') : 'unknown'), 'label' => is_array($release) ? ($available ? Localization::t('updates.available', ['version' => (string) ($release['version'] ?? '')]) : Localization::t('updates.up_to_date')) : Localization::t('updates.not_checked')]) ?></div>
        <div class="grid sm:grid-2 md:grid-3">
            <?= Components::render('ui/stat-card', ['label' => Localization::t('updates.current_version'), 'value' => 'v' . \TinyCat\Support\Version::CURRENT, 'icon' => 'download']) ?>
            <?= Components::render('ui/stat-card', ['label' => Localization::t('updates.latest_version'), 'value' => is_array($release) ? 'v' . (string) ($release['version'] ?? '?') : '-', 'icon' => 'refresh']) ?>
            <?= Components::render('ui/stat-card', ['label' => Localization::t('updates.requirements'), 'value' => count(array_filter($extensions)) . ' / ' . count($extensions), 'icon' => 'shield']) ?>
        </div>
        <?php if (!is_array($release)): ?><p class="table-meta m-0"><?= Html::et('updates.check_help') ?></p><?php endif; ?>
        <?php if (is_array($release) && !$releaseCompatible): ?>
            <?= Components::render('ui/notice', ['tone' => 'warning', 'title' => Localization::t('updates.incompatible', ['version' => (string) ($release['manifest']['minimum_version'] ?? '?'), 'php' => (string) ($release['manifest']['minimum_php'] ?? '?')])]) ?>
        <?php endif; ?>
        <?php if (in_array(false, $extensions, true)): ?><?= Components::render('ui/notice', ['tone' => 'critical', 'title' => Localization::t('updates.missing_extensions')]) ?><?php endif; ?>
        <?php if (is_array($release) && trim((string) ($release['notes'] ?? '')) !== ''): ?>
            <?= $releaseNotesModal ?>
        <?php endif; ?>
</div>
    <details class="disclosure-item">
        <summary class="disclosure-summary"><span><?= Html::et('updates.requirements') ?></span><?= Html::icon('chevron-down', 'icon disclosure-chevron') ?></summary>
        <div class="card-body stack">
            <ul class="result-list">
                <?php foreach ($extensions as $extension => $loaded): ?>
                    <li class="result-item">
                        <?= Components::render('ui/status-badge', ['tone' => $loaded ? 'ok' : 'critical', 'label' => Localization::t($loaded ? 'common.done' : 'updates.missing_extensions')]) ?>
                        <span>PHP <?= \TinyCat\Support\Html::e($extension) ?></span>
                    </li>
                <?php endforeach; ?>
                <li class="result-item"><?= Components::render('ui/status-badge', ['tone' => 'ok', 'label' => Localization::t('common.done')]) ?> <span><?= \TinyCat\Support\Html::et('updates.signed_packages') ?></span></li>
                <li class="result-item"><?= Components::render('ui/status-badge', ['tone' => 'ok', 'label' => Localization::t('common.done')]) ?> <span><?= \TinyCat\Support\Html::et('updates.automatic_backup') ?></span></li>
                <li class="result-item"><?= Components::render('ui/status-badge', ['tone' => 'ok', 'label' => Localization::t('common.done')]) ?> <span><?= \TinyCat\Support\Html::et('updates.protected_runtime') ?></span></li>
            </ul>
            <?php if (in_array(false, $extensions, true)): ?>
                <div class="alert alert-warning"><?= \TinyCat\Support\Html::et('updates.missing_extensions') ?></div>
            <?php endif; ?>
        </div>
    </details>

<?php if ($result !== []): ?>
    <section class="card mt-6">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('check-circle') ?> <?= \TinyCat\Support\Html::et('updates.last_result') ?></h2></div>
        <div class="card-body stack">
            <p class="m-0"><?= \TinyCat\Support\Html::et('updates.installed_version') ?> <strong>v<?= \TinyCat\Support\Html::e((string) ($result['version'] ?? '')) ?></strong></p>
            <?php if (!empty($result['backup'])): ?><p class="m-0"><?= \TinyCat\Support\Html::et('updates.backup_path') ?> <code><?= \TinyCat\Support\Html::e((string) $result['backup']) ?></code></p><?php endif; ?>
            <p class="m-0"><?= \TinyCat\Support\Html::et('updates.applied_migrations') ?> <strong><?= \TinyCat\Support\Html::e((string) count((array) ($result['migrations'] ?? []))) ?></strong></p>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-header"><h2 class="text-lg m-0"><?= Html::et('updates.migration_history') ?></h2></div>
    <div class="card-body">
        <?php if ($history === []): ?>
            <?= Components::render('ui/empty-state', ['label' => Localization::t('updates.no_migrations'), 'icon' => 'clock']) ?>
        <?php else: ?>
            <div class="table-wrap min-w-0">
                <table class="table">
                    <thead><tr><th><?= \TinyCat\Support\Html::et('updates.migration') ?></th><th><?= \TinyCat\Support\Html::et('updates.version') ?></th><th><?= \TinyCat\Support\Html::et('updates.applied_at') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $migration): ?>
                        <tr>
                            <td><div class="table-identity"><div class="table-identity-body"><code class="table-primary" title="<?= \TinyCat\Support\Html::e((string) ($migration['migration'] ?? '')) ?>"><?= \TinyCat\Support\Html::e((string) ($migration['migration'] ?? '')) ?></code></div></div></td>
                            <td>v<?= \TinyCat\Support\Html::e((string) ($migration['version'] ?? '')) ?></td>
                            <td><time class="table-meta" datetime="<?= Html::e((string) ($migration['applied_at'] ?? '')) ?>"><?= Html::e(\TinyCat\Support\Dates::displayDateTime((string) ($migration['applied_at'] ?? ''))) ?></time></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

</div>
