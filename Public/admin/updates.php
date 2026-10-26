<?php
declare(strict_types=1);

use \TinyCat\Updates\Manager;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

\TinyCat\Users\Access::require_admin();

if (\TinyCat\Http\Request::current()->isPost()) {
    \TinyCat\Security\Csrf::requireToken();
    $action = (string) \TinyCat\Http\Web::post('action', 'check');

    try {
        if ($action === 'check') {
            $release = Manager::check(true);
            \TinyCat\Http\Session::flash('updater_release', $release);
            \TinyCat\Http\Session::flash('success', !empty($release['available'])
                ? \TinyCat\Support\Localization::t('updates.messages.available', ['version' => (string) ($release['version'] ?? '')])
                : \TinyCat\Support\Localization::t('updates.messages.current'));
        } elseif ($action === 'install') {
            $result = Manager::installLatest();
            \TinyCat\Http\Session::flash('updater_result', $result);
            \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('updates.messages.installed', ['version' => (string) ($result['version'] ?? \TinyCat\Support\Version::CURRENT)]));
        } elseif ($action === 'disable-maintenance') {
            Manager::disableMaintenance();
            \TinyCat\Http\Session::flash('success', \TinyCat\Support\Localization::t('updates.messages.maintenance_disabled'));
        } else {
            throw new RuntimeException(\TinyCat\Support\Localization::t('updates.messages.invalid_action'));
        }
    } catch (Throwable $exception) {
        \TinyCat\Http\Session::flash('updater_error', $exception->getMessage());
        \TinyCat\Http\Session::flash('error', \TinyCat\Support\Localization::t('updates.messages.failed'));
    }

    \TinyCat\Http\Web::redirect('/admin/updates');
}

$release = \TinyCat\Http\Session::flash('updater_release');
$release = is_array($release) ? $release : Manager::cachedRelease();
$result = \TinyCat\Http\Session::flash('updater_result');
$result = is_array($result) ? $result : [];
$error = trim((string) \TinyCat\Http\Session::flash('updater_error'));
$maintenance = Manager::maintenanceState();
$extensions = [
    'cURL' => extension_loaded('curl'),
    'Sodium' => extension_loaded('sodium'),
    'ZIP / Phar' => class_exists('ZipArchive') || class_exists('PharData'),
];
$releaseCompatible = !is_array($release) || !array_key_exists('compatible', $release) || !empty($release['compatible']);
$canInstall = !in_array(false, $extensions, true) && $releaseCompatible;
$history = Manager::migrationHistory();

\TinyCat\Support\View::layout('layout', [
    'title' => \TinyCat\Support\Localization::t('updates.title'),
    'current' => '/admin/updates',
], static function () use ($release, $result, $error, $maintenance, $extensions, $releaseCompatible, $canInstall, $history): void {
    $available = is_array($release) && !empty($release['available']);
    ?>
    <?php if ($maintenance !== []): ?>
        <section class="alert alert-warning stack">
            <strong class="cluster gap-2"><?= \TinyCat\Support\Html::icon('alert') ?> <?= \TinyCat\Support\Html::et('updates.maintenance_active') ?></strong>
            <span><?= \TinyCat\Support\Html::et('updates.maintenance_active_help', [
                'from' => (string) ($maintenance['from_version'] ?? \TinyCat\Support\Version::CURRENT),
                'to' => (string) ($maintenance['to_version'] ?? '?'),
            ]) ?></span>
            <form method="post" action="/admin/updates" data-confirm="<?= \TinyCat\Support\Html::et('updates.maintenance_disable_confirm') ?>" data-confirm-variant="danger">
                <?= \TinyCat\Security\Csrf::field() ?>
                <input type="hidden" name="action" value="disable-maintenance">
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

    <section class="grid md:grid-2">
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('download') ?> <?= \TinyCat\Support\Html::et('updates.release_title') ?></h2>
            </div>
            <div class="card-body stack">
                <div class="split">
                    <span><?= \TinyCat\Support\Html::et('updates.current_version') ?></span>
                    <strong>v<?= \TinyCat\Support\Html::e(\TinyCat\Support\Version::CURRENT) ?></strong>
                </div>
                <div class="split">
                    <span><?= \TinyCat\Support\Html::et('updates.latest_version') ?></span>
                    <strong><?= is_array($release) ? 'v' . \TinyCat\Support\Html::e((string) ($release['version'] ?? '?')) : \TinyCat\Support\Html::et('updates.not_checked') ?></strong>
                </div>

                <?php if (is_array($release)): ?>
                    <div class="alert <?= $available ? 'alert-info' : 'alert-success' ?>">
                        <?= $available
                            ? \TinyCat\Support\Html::et('updates.available', ['version' => (string) ($release['version'] ?? '')])
                            : \TinyCat\Support\Html::et('updates.up_to_date') ?>
                    </div>
                    <?php if (trim((string) ($release['notes'] ?? '')) !== ''): ?>
                        <details>
                            <summary><?= \TinyCat\Support\Html::et('updates.changelog') ?></summary>
                            <pre class="code-block"><code><?= \TinyCat\Support\Html::e((string) $release['notes']) ?></code></pre>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted m-0"><?= \TinyCat\Support\Html::et('updates.check_help') ?></p>
                <?php endif; ?>
                <?php if (is_array($release) && !$releaseCompatible): ?>
                    <div class="alert alert-warning"><?= \TinyCat\Support\Html::et('updates.incompatible', [
                        'version' => (string) (($release['manifest']['minimum_version'] ?? '?')),
                        'php' => (string) (($release['manifest']['minimum_php'] ?? '?')),
                    ]) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-footer cluster justify-end">
                <form method="post" action="/admin/updates">
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <input type="hidden" name="action" value="check">
                    <button class="btn btn-secondary" type="submit"><?= \TinyCat\Support\Html::icon('refresh') ?> <span><?= \TinyCat\Support\Html::et('updates.check') ?></span></button>
                </form>
                <?php if ($available): ?>
                    <form method="post" action="/admin/updates" data-confirm="<?= \TinyCat\Support\Html::et('updates.install_confirm', ['version' => (string) ($release['version'] ?? '')]) ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('updates.install_title') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('updates.install') ?>">
                        <?= \TinyCat\Security\Csrf::field() ?>
                        <input type="hidden" name="action" value="install">
                        <button class="btn btn-primary" type="submit"<?= $canInstall ? '' : ' disabled' ?>><?= \TinyCat\Support\Html::icon('download') ?> <span><?= \TinyCat\Support\Html::et('updates.install') ?></span></button>
                    </form>
                <?php endif; ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('shield') ?> <?= \TinyCat\Support\Html::et('updates.requirements') ?></h2>
            </div>
            <div class="card-body stack">
                <ul class="result-list">
                    <?php foreach ($extensions as $extension => $loaded): ?>
                        <li class="result-item">
                            <?= \TinyCat\Support\Html::icon($loaded ? 'check-circle' : 'x-circle', $loaded ? 'icon text-primary' : 'icon text-danger') ?>
                            <span>PHP <?= \TinyCat\Support\Html::e($extension) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="result-item"><?= \TinyCat\Support\Html::icon('check-circle', 'icon text-primary') ?> <span><?= \TinyCat\Support\Html::et('updates.signed_packages') ?></span></li>
                    <li class="result-item"><?= \TinyCat\Support\Html::icon('check-circle', 'icon text-primary') ?> <span><?= \TinyCat\Support\Html::et('updates.automatic_backup') ?></span></li>
                    <li class="result-item"><?= \TinyCat\Support\Html::icon('check-circle', 'icon text-primary') ?> <span><?= \TinyCat\Support\Html::et('updates.protected_runtime') ?></span></li>
                </ul>
                <?php if (!$canInstall): ?>
                    <div class="alert alert-warning"><?= \TinyCat\Support\Html::et('updates.missing_extensions') ?></div>
                <?php endif; ?>
            </div>
        </article>
    </section>

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

    <section class="card mt-6">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('database') ?> <?= \TinyCat\Support\Html::et('updates.migration_history') ?></h2></div>
        <div class="card-body">
            <?php if ($history === []): ?>
                <p class="text-muted m-0"><?= \TinyCat\Support\Html::et('updates.no_migrations') ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th><?= \TinyCat\Support\Html::et('updates.migration') ?></th><th><?= \TinyCat\Support\Html::et('updates.version') ?></th><th><?= \TinyCat\Support\Html::et('updates.applied_at') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $migration): ?>
                            <tr>
                                <td><code><?= \TinyCat\Support\Html::e((string) ($migration['migration'] ?? '')) ?></code></td>
                                <td>v<?= \TinyCat\Support\Html::e((string) ($migration['version'] ?? '')) ?></td>
                                <td><?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::displayDateTime((string) ($migration['applied_at'] ?? ''))) ?></td>
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
