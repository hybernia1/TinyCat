<?php
declare(strict_types=1);

use TinyCat\Update\Manager;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();
    $action = (string) post('action', 'check');

    try {
        if ($action === 'check') {
            $release = Manager::check(true);
            flash('updater_release', $release);
            flash('success', !empty($release['available'])
                ? t('updates.messages.available', ['version' => (string) ($release['version'] ?? '')])
                : t('updates.messages.current'));
        } elseif ($action === 'install') {
            $result = Manager::installLatest();
            flash('updater_result', $result);
            flash('success', t('updates.messages.installed', ['version' => (string) ($result['version'] ?? Core::VERSION)]));
        } elseif ($action === 'disable-maintenance') {
            Manager::disableMaintenance();
            flash('success', t('updates.messages.maintenance_disabled'));
        } else {
            throw new RuntimeException(t('updates.messages.invalid_action'));
        }
    } catch (Throwable $exception) {
        flash('updater_error', $exception->getMessage());
        flash('error', t('updates.messages.failed'));
    }

    redirect('/admin/updates');
}

$release = flash('updater_release');
$release = is_array($release) ? $release : Manager::cachedRelease();
$result = flash('updater_result');
$result = is_array($result) ? $result : [];
$error = trim((string) flash('updater_error'));
$maintenance = Manager::maintenanceState();
$extensions = [
    'cURL' => extension_loaded('curl'),
    'Sodium' => extension_loaded('sodium'),
    'ZIP / Phar' => class_exists('ZipArchive') || class_exists('PharData'),
];
$releaseCompatible = !is_array($release) || !array_key_exists('compatible', $release) || !empty($release['compatible']);
$canInstall = !in_array(false, $extensions, true) && $releaseCompatible;
$history = Manager::migrationHistory();

layout('layout', [
    'title' => t('updates.title'),
    'current' => '/admin/updates',
], static function () use ($release, $result, $error, $maintenance, $extensions, $releaseCompatible, $canInstall, $history): void {
    $available = is_array($release) && !empty($release['available']);
    ?>
    <?php if ($maintenance !== []): ?>
        <section class="alert alert-warning stack">
            <strong class="cluster gap-2"><?= icon('alert') ?> <?= et('updates.maintenance_active') ?></strong>
            <span><?= et('updates.maintenance_active_help', [
                'from' => (string) ($maintenance['from_version'] ?? Core::VERSION),
                'to' => (string) ($maintenance['to_version'] ?? '?'),
            ]) ?></span>
            <form method="post" action="/admin/updates" data-confirm="<?= et('updates.maintenance_disable_confirm') ?>" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="disable-maintenance">
                <button class="btn btn-danger btn-sm" type="submit"><?= icon('unlock') ?> <span><?= et('updates.maintenance_disable') ?></span></button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <section class="alert alert-danger stack">
            <strong><?= et('updates.error_detail') ?></strong>
            <code><?= e($error) ?></code>
        </section>
    <?php endif; ?>

    <section class="grid md:grid-2">
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('download') ?> <?= et('updates.release_title') ?></h2>
            </div>
            <div class="card-body stack">
                <div class="split gap-3">
                    <span><?= et('updates.current_version') ?></span>
                    <strong>v<?= e(Core::VERSION) ?></strong>
                </div>
                <div class="split gap-3">
                    <span><?= et('updates.latest_version') ?></span>
                    <strong><?= is_array($release) ? 'v' . e((string) ($release['version'] ?? '?')) : et('updates.not_checked') ?></strong>
                </div>

                <?php if (is_array($release)): ?>
                    <div class="alert <?= $available ? 'alert-info' : 'alert-success' ?>">
                        <?= $available
                            ? et('updates.available', ['version' => (string) ($release['version'] ?? '')])
                            : et('updates.up_to_date') ?>
                    </div>
                    <?php if (trim((string) ($release['notes'] ?? '')) !== ''): ?>
                        <details>
                            <summary><?= et('updates.changelog') ?></summary>
                            <pre class="code-block"><code><?= e((string) $release['notes']) ?></code></pre>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted m-0"><?= et('updates.check_help') ?></p>
                <?php endif; ?>
                <?php if (is_array($release) && !$releaseCompatible): ?>
                    <div class="alert alert-warning"><?= et('updates.incompatible', [
                        'version' => (string) (($release['manifest']['minimum_version'] ?? '?')),
                        'php' => (string) (($release['manifest']['minimum_php'] ?? '?')),
                    ]) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-footer cluster justify-end">
                <form method="post" action="/admin/updates">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="check">
                    <button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> <span><?= et('updates.check') ?></span></button>
                </form>
                <?php if ($available): ?>
                    <form method="post" action="/admin/updates" data-confirm="<?= et('updates.install_confirm', ['version' => (string) ($release['version'] ?? '')]) ?>" data-confirm-title="<?= et('updates.install_title') ?>" data-confirm-ok="<?= et('updates.install') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="install">
                        <button class="btn btn-primary" type="submit"<?= $canInstall ? '' : ' disabled' ?>><?= icon('download') ?> <span><?= et('updates.install') ?></span></button>
                    </form>
                <?php endif; ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('shield') ?> <?= et('updates.requirements') ?></h2>
            </div>
            <div class="card-body stack">
                <ul class="result-list">
                    <?php foreach ($extensions as $extension => $loaded): ?>
                        <li class="result-item">
                            <?= icon($loaded ? 'check-circle' : 'x-circle', $loaded ? 'icon text-primary' : 'icon text-danger') ?>
                            <span>PHP <?= e($extension) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="result-item"><?= icon('check-circle', 'icon text-primary') ?> <span><?= et('updates.signed_packages') ?></span></li>
                    <li class="result-item"><?= icon('check-circle', 'icon text-primary') ?> <span><?= et('updates.automatic_backup') ?></span></li>
                    <li class="result-item"><?= icon('check-circle', 'icon text-primary') ?> <span><?= et('updates.protected_runtime') ?></span></li>
                </ul>
                <?php if (!$canInstall): ?>
                    <div class="alert alert-warning"><?= et('updates.missing_extensions') ?></div>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <?php if ($result !== []): ?>
        <section class="card mt-6">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('check-circle') ?> <?= et('updates.last_result') ?></h2></div>
            <div class="card-body stack">
                <p class="m-0"><?= et('updates.installed_version') ?> <strong>v<?= e((string) ($result['version'] ?? '')) ?></strong></p>
                <?php if (!empty($result['backup'])): ?><p class="m-0"><?= et('updates.backup_path') ?> <code><?= e((string) $result['backup']) ?></code></p><?php endif; ?>
                <p class="m-0"><?= et('updates.applied_migrations') ?> <strong><?= e((string) count((array) ($result['migrations'] ?? []))) ?></strong></p>
            </div>
        </section>
    <?php endif; ?>

    <section class="card mt-6">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('database') ?> <?= et('updates.migration_history') ?></h2></div>
        <div class="card-body">
            <?php if ($history === []): ?>
                <p class="text-muted m-0"><?= et('updates.no_migrations') ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th><?= et('updates.migration') ?></th><th><?= et('updates.version') ?></th><th><?= et('updates.applied_at') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $migration): ?>
                            <tr>
                                <td><code><?= e((string) ($migration['migration'] ?? '')) ?></code></td>
                                <td>v<?= e((string) ($migration['version'] ?? '')) ?></td>
                                <td><?= e(datetime((string) ($migration['applied_at'] ?? ''))) ?></td>
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
