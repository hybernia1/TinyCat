<?php
declare(strict_types=1);

use TinyCat\Extension\Lifecycle;
use TinyCat\Extension\Loader;
use TinyCat\Extension\Store;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();
    $action = strtolower(trim((string) post('action', 'state')));
    $slug = strtolower(trim((string) post('slug', '')));

    try {
        if ($action === 'install') {
            $result = Store::install($slug);
            flash('success', t(!empty($result['updated'])
                ? 'extensions.messages.updated'
                : 'extensions.messages.installed', [
                'name' => (string) ($result['name'] ?? $slug),
                'version' => (string) ($result['version'] ?? ''),
            ]));
        } elseif ($action === 'uninstall') {
            $result = Store::uninstall($slug, (string) post('uninstall_mode', ''));
            flash('success', t(!empty($result['data_removed'])
                ? 'extensions.messages.uninstalled_removed'
                : 'extensions.messages.uninstalled_kept', [
                'name' => (string) ($result['name'] ?? $slug),
            ]));
        } elseif ($action === 'refresh') {
            Store::catalog(true);
            flash('success', t('extensions.messages.refreshed'));
        } elseif ($action === 'state') {
            $enabledInput = (string) post('enabled', '');
            $extensions = Lifecycle::all();

            if (!isset($extensions[$slug])) {
                throw new RuntimeException(t('extensions.messages.not_found'));
            }

            $extension = $extensions[$slug];
            if (!in_array($enabledInput, ['0', '1'], true)) {
                throw new RuntimeException(t('extensions.messages.invalid_state'));
            }

            $enabled = $enabledInput === '1';
            if ($enabled && empty($extension['compatible'])) {
                throw new RuntimeException(t('extensions.messages.incompatible', [
                    'version' => (string) ($extension['minimum_tinycat'] ?? ''),
                ]));
            }
            if ($enabled && empty($extension['installed'])) {
                throw new RuntimeException(t('extensions.messages.install_first'));
            }

            $states = Loader::stateOverrides();
            $defaultEnabled = !empty($extension['autoload']);
            if ($enabled === $defaultEnabled) unset($states[$slug]);
            else $states[$slug] = $enabled;

            setting_set('extensions.states', $states, 'json', 'extensions');
            flash('success', t($enabled ? 'extensions.messages.enabled' : 'extensions.messages.disabled', [
                'name' => (string) ($extension['name'] ?? $slug),
            ]));
        } else {
            throw new RuntimeException(t('extensions.messages.invalid_action'));
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }

    redirect('/admin/extensions');
}

$installed = Lifecycle::all();
$store = [];
$storeMeta = [];
$storeError = '';

try {
    $storeMeta = Store::catalog();
    $store = (array) ($storeMeta['extensions'] ?? []);
} catch (Throwable $exception) {
    $storeError = $exception->getMessage();
}

$slugs = array_values(array_unique([...array_keys($installed), ...array_keys($store)]));
sort($slugs, SORT_STRING);
$localizedManifestText = static function (array $values): string {
    $current = strtolower(str_replace('_', '-', locale()));
    $language = substr($current, 0, 2);

    return (string) ($values[$current] ?? $values[$language] ?? $values['en'] ?? reset($values) ?: '');
};

layout('layout', [
    'title' => t('extensions.title'),
    'current' => '/admin/extensions',
], static function () use ($installed, $store, $storeMeta, $storeError, $slugs, $localizedManifestText): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <div>
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('puzzle') ?> <?= et('extensions.title') ?></h1>
                <p class="text-muted mb-0"><?= et('extensions.intro') ?></p>
            </div>
            <form method="post" action="/admin/extensions">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="refresh">
                <button class="btn btn-secondary btn-sm" type="submit"><?= icon('refresh') ?> <span><?= et('extensions.refresh') ?></span></button>
            </form>
        </div>
        <div class="card-body stack">
            <?php if ($storeError !== ''): ?>
                <div class="alert alert-warning mb-0"><?= et('extensions.store_unavailable') ?> <code><?= e($storeError) ?></code></div>
            <?php elseif (!empty($storeMeta['release_url'])): ?>
                <p class="text-muted mb-0">
                    <?= et('extensions.official_store') ?>
                    <a href="<?= e((string) $storeMeta['release_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) ($storeMeta['repository'] ?? '')) ?></a>
                </p>
            <?php endif; ?>

            <?php if ($slugs === []): ?>
                <p class="text-muted m-0"><?= et('extensions.empty') ?></p>
            <?php else: ?>
                <div class="disclosure-list">
                    <?php foreach ($slugs as $slug): ?>
                        <?php
                        $local = is_array($installed[$slug] ?? null) ? $installed[$slug] : [];
                        $remote = is_array($store[$slug] ?? null) ? $store[$slug] : [];
                        $present = $local !== [];
                        $enabled = $present && !empty($local['enabled']);
                        $requestedEnabled = $present && !empty($local['requested_enabled']);
                        $installedVersion = (string) ($local['installed_version'] ?? '');
                        $codeVersion = (string) ($local['version'] ?? '');
                        $storeVersion = (string) ($remote['version'] ?? '');
                        $storeCompatible = $remote === [] || !empty($remote['compatible']);
                        $updateAvailable = $present && $storeVersion !== '' && version_compare($storeVersion, $codeVersion, '>');
                        $repairRequired = $present && $installedVersion !== $codeVersion;
                        $uninstall = is_array($local['uninstall'] ?? null) ? $local['uninstall'] : null;
                        $canUninstall = $present
                            && !empty($local['installed'])
                            && !$requestedEnabled
                            && $uninstall !== null;
                        $description = (string) (($remote['descriptions'][locale()] ?? null)
                            ?? ($remote['descriptions']['en'] ?? null)
                            ?? '');
                        $name = (string) ($local['name'] ?? $remote['name'] ?? $slug);
                        ?>
                        <details class="disclosure-item"<?= !$present || $updateAvailable || $repairRequired ? ' open' : '' ?>>
                            <summary class="disclosure-summary">
                                <span class="disclosure-heading">
                                    <?= icon('puzzle') ?>
                                    <span class="stack stack-gap-4">
                                        <strong><?= e($name) ?></strong>
                                        <small><code><?= e((string) $slug) ?></code></small>
                                    </span>
                                </span>
                                <span class="cluster gap-2">
                                    <?php if ($updateAvailable || $repairRequired): ?>
                                        <span class="badge badge-primary"><?= et('extensions.status_update') ?></span>
                                    <?php elseif (!$present): ?>
                                        <span class="badge"><?= et('extensions.status_available') ?></span>
                                    <?php else: ?>
                                        <span class="badge <?= $enabled ? 'badge-primary' : '' ?>"><?= et($enabled ? 'extensions.status_enabled' : 'extensions.status_disabled') ?></span>
                                    <?php endif; ?>
                                </span>
                            </summary>
                            <div class="disclosure-body stack stack-gap-8">
                                <?php if ($description !== ''): ?><p class="mb-0"><?= e($description) ?></p><?php endif; ?>
                                <div class="split">
                                    <span class="text-muted"><?= et('extensions.installed_version') ?></span>
                                    <strong><?= $installedVersion !== '' ? 'v' . e($installedVersion) : et('extensions.not_installed') ?></strong>
                                </div>
                                <?php if ($storeVersion !== ''): ?>
                                    <div class="split">
                                        <span class="text-muted"><?= et('extensions.store_version') ?></span>
                                        <strong>v<?= e($storeVersion) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="split">
                                    <span class="text-muted"><?= et('extensions.minimum_tinycat') ?></span>
                                    <strong>v<?= e((string) ($remote['minimum_tinycat'] ?? $local['minimum_tinycat'] ?? '')) ?></strong>
                                </div>
                                <?php if (!$storeCompatible): ?>
                                    <div class="alert alert-warning mb-0"><?= et('extensions.incompatible_help', [
                                        'version' => (string) ($remote['minimum_tinycat'] ?? ''),
                                    ]) ?></div>
                                <?php elseif (!empty($local['downgrade_detected'])): ?>
                                    <div class="alert alert-danger mb-0"><?= et('extensions.downgrade_help') ?></div>
                                <?php elseif ($present && $repairRequired): ?>
                                    <div class="alert alert-warning mb-0"><?= et('extensions.update_required_help') ?></div>
                                <?php elseif ($present): ?>
                                    <p class="text-muted mb-0"><?= et($enabled ? 'extensions.disable_help' : 'extensions.enable_help') ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer cluster justify-end">
                                <?php if (!empty($remote['homepage'])): ?>
                                    <a class="btn btn-ghost" href="<?= e((string) $remote['homepage']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external-link') ?> <span><?= et('extensions.details') ?></span></a>
                                <?php endif; ?>
                                <?php if ($remote !== [] && (!$present || $updateAvailable || $repairRequired)): ?>
                                    <form method="post" action="/admin/extensions" data-confirm="<?= et($present ? 'extensions.update_confirm' : 'extensions.install_confirm', ['name' => $name]) ?>" data-confirm-title="<?= et($present ? 'extensions.update' : 'extensions.install') ?>" data-confirm-ok="<?= et($present ? 'extensions.update' : 'extensions.install') ?>" data-confirm-cancel="<?= et('common.cancel') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="install">
                                        <input type="hidden" name="slug" value="<?= e((string) $slug) ?>">
                                        <button class="btn btn-primary" type="submit"<?= !$storeCompatible ? ' disabled' : '' ?>><?= icon('download') ?> <span><?= et($present ? 'extensions.update' : 'extensions.install') ?></span></button>
                                    </form>
                                <?php elseif ($present): ?>
                                    <form method="post" action="/admin/extensions"<?= $requestedEnabled
                                        ? ' data-confirm="' . et('extensions.disable_confirm', ['name' => $name])
                                            . '" data-confirm-title="' . et('extensions.disable_title')
                                            . '" data-confirm-ok="' . et('extensions.disable')
                                            . '" data-confirm-cancel="' . et('common.cancel')
                                            . '" data-confirm-variant="danger"'
                                        : '' ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="state">
                                        <input type="hidden" name="slug" value="<?= e((string) $slug) ?>">
                                        <input type="hidden" name="enabled" value="<?= $requestedEnabled ? '0' : '1' ?>">
                                        <button class="btn <?= $requestedEnabled ? 'btn-danger' : 'btn-primary' ?>" type="submit"<?= !$requestedEnabled && (empty($local['compatible']) || empty($local['installed'])) ? ' disabled' : '' ?>>
                                            <?= icon($requestedEnabled ? 'close' : 'check') ?>
                                            <span><?= et($requestedEnabled ? 'extensions.disable' : 'extensions.enable') ?></span>
                                        </button>
                                    </form>
                                    <?php if ($canUninstall): ?>
                                        <button class="btn btn-danger" type="button" data-modal-open="extension-uninstall-<?= e((string) $slug) ?>">
                                            <?= icon('trash') ?> <span><?= et('extensions.uninstall') ?></span>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php foreach ($installed as $slug => $extension): ?>
        <?php
        $uninstall = is_array($extension['uninstall'] ?? null) ? $extension['uninstall'] : null;
        if ($uninstall === null || !empty($extension['requested_enabled']) || empty($extension['installed'])) {
            continue;
        }

        $options = (array) ($uninstall['options'] ?? []);
        $hasRecommended = count(array_filter($options, static fn (array $option): bool => !empty($option['recommended']))) > 0;
        ob_start();
        ?>
        <input type="hidden" name="action" value="uninstall">
        <input type="hidden" name="slug" value="<?= e((string) $slug) ?>">
        <p class="mb-0"><?= et('extensions.uninstall_intro', ['name' => (string) ($extension['name'] ?? $slug)]) ?></p>
        <div class="stack stack-gap-8">
            <?php foreach ($options as $index => $option): ?>
                <?php
                $recommended = !empty($option['recommended']);
                $checked = $recommended || (!$hasRecommended && $index === 0);
                ?>
                <label class="check-card">
                    <input type="radio" name="uninstall_mode" value="<?= e((string) ($option['id'] ?? '')) ?>"<?= $checked ? ' checked' : '' ?> required>
                    <span class="check-card-body">
                        <strong>
                            <?= e($localizedManifestText((array) ($option['labels'] ?? []))) ?>
                            <?php if ($recommended): ?><span class="badge badge-primary"><?= et('extensions.recommended') ?></span><?php endif; ?>
                            <?php if (!empty($option['danger'])): ?><span class="badge badge-danger"><?= et('extensions.destructive') ?></span><?php endif; ?>
                        </strong>
                        <small><?= e($localizedManifestText((array) ($option['descriptions'] ?? []))) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="alert alert-warning mb-0"><?= et('extensions.uninstall_backup_help') ?></div>
        <?php
        $body = (string) ob_get_clean();
        $footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
            . '<button class="btn btn-danger" type="submit">' . icon('trash') . ' <span>' . et('extensions.uninstall') . '</span></button>';

        echo render('modals/layout', [
            'id' => 'extension-uninstall-' . $slug,
            'title' => t('extensions.uninstall_title', ['name' => (string) ($extension['name'] ?? $slug)]),
            'icon' => 'trash',
            'action' => '/admin/extensions',
            'ajax' => false,
            'body' => $body,
            'footer' => $footer,
        ]);
        ?>
    <?php endforeach; ?>
    <?php
});
