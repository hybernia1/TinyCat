<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (is_post()) {
    csrf_require();
    $slug = strtolower(trim((string) post('slug', '')));
    $enabledInput = (string) post('enabled', '');
    $extensions = ExtensionLifecycle::all();

    try {
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

        $states = ExtensionLoader::stateOverrides();
        $defaultEnabled = !empty($extension['autoload']);

        if ($enabled === $defaultEnabled) {
            unset($states[$slug]);
        } else {
            $states[$slug] = $enabled;
        }

        setting_set('extensions.states', $states, 'json', 'extensions');
        flash('success', t($enabled ? 'extensions.messages.enabled' : 'extensions.messages.disabled', [
            'name' => (string) ($extension['name'] ?? $slug),
        ]));
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }

    redirect('/admin/extensions');
}

$extensions = ExtensionLifecycle::all();

layout('layout', [
    'title' => t('extensions.title'),
    'current' => '/admin/extensions',
], static function () use ($extensions): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= icon('file') ?> <?= et('extensions.title') ?></h1>
            <p class="text-muted mb-0"><?= et('extensions.intro') ?></p>
        </div>
        <div class="card-body stack">
            <?php if ($extensions === []): ?>
                <p class="text-muted m-0"><?= et('extensions.empty') ?></p>
            <?php else: ?>
                <div class="grid md:grid-2">
                    <?php foreach ($extensions as $slug => $extension): ?>
                        <?php
                        $enabled = !empty($extension['enabled']);
                        $requestedEnabled = !empty($extension['requested_enabled']);
                        $compatible = !empty($extension['compatible']);
                        $installed = !empty($extension['installed']);
                        $installedVersion = (string) ($extension['installed_version'] ?? '');
                        $pendingMigrations = (int) ($extension['pending_migrations'] ?? 0);
                        $migrationError = (string) ($extension['migration_error'] ?? '');
                        $downgradeDetected = !empty($extension['downgrade_detected']);
                        $updateAvailable = !empty($extension['update_available']);
                        ?>
                        <article class="card">
                            <div class="card-header split gap-3">
                                <div>
                                    <h2 class="text-lg m-0"><?= e((string) ($extension['name'] ?? $slug)) ?></h2>
                                    <code><?= e((string) $slug) ?></code>
                                </div>
                                <span class="badge <?= $enabled ? 'badge-primary' : ($compatible ? '' : 'badge-danger') ?>">
                                    <?= et($enabled
                                        ? 'extensions.status_enabled'
                                        : ($compatible ? 'extensions.status_disabled' : 'extensions.status_incompatible')) ?>
                                </span>
                            </div>
                            <div class="card-body stack gap-2">
                                <div class="split gap-3">
                                    <span class="text-muted"><?= et('extensions.version') ?></span>
                                    <strong>v<?= e((string) ($extension['version'] ?? '')) ?></strong>
                                </div>
                                <div class="split gap-3">
                                    <span class="text-muted"><?= et('extensions.installed_version') ?></span>
                                    <strong><?= $installedVersion !== '' ? 'v' . e($installedVersion) : et('extensions.not_installed') ?></strong>
                                </div>
                                <div class="split gap-3">
                                    <span class="text-muted"><?= et('extensions.minimum_tinycat') ?></span>
                                    <strong>v<?= e((string) ($extension['minimum_tinycat'] ?? '')) ?></strong>
                                </div>
                                <div class="split gap-3">
                                    <span class="text-muted"><?= et('extensions.entry') ?></span>
                                    <code><?= e((string) ($extension['entry'] ?? '')) ?></code>
                                </div>
                                <?php if ($pendingMigrations > 0): ?>
                                    <div class="split gap-3">
                                        <span class="text-muted"><?= et('extensions.pending_migrations') ?></span>
                                        <strong><?= e((string) $pendingMigrations) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ($migrationError !== ''): ?>
                                    <div class="alert alert-danger mb-0"><code><?= e($migrationError) ?></code></div>
                                <?php elseif ($downgradeDetected): ?>
                                    <div class="alert alert-danger mb-0"><?= et('extensions.downgrade_help') ?></div>
                                <?php elseif (!$compatible): ?>
                                    <div class="alert alert-warning mb-0"><?= et('extensions.incompatible_help', [
                                        'version' => (string) ($extension['minimum_tinycat'] ?? ''),
                                    ]) ?></div>
                                <?php elseif (!$installed): ?>
                                    <div class="alert alert-warning mb-0"><?= et('extensions.not_installed_help') ?></div>
                                <?php elseif ($updateAvailable || $pendingMigrations > 0): ?>
                                    <div class="alert alert-warning mb-0"><?= et('extensions.update_required_help') ?></div>
                                <?php elseif ($enabled): ?>
                                    <p class="text-muted mb-0"><?= et('extensions.disable_help') ?></p>
                                <?php else: ?>
                                    <p class="text-muted mb-0"><?= et('extensions.enable_help') ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer cluster justify-end">
                                <form method="post" action="/admin/extensions"<?= $requestedEnabled
                                    ? ' data-confirm="' . et('extensions.disable_confirm', ['name' => (string) ($extension['name'] ?? $slug)])
                                        . '" data-confirm-title="' . et('extensions.disable_title')
                                        . '" data-confirm-ok="' . et('extensions.disable')
                                        . '" data-confirm-cancel="' . et('common.cancel')
                                        . '" data-confirm-variant="danger"'
                                    : '' ?>>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="slug" value="<?= e((string) $slug) ?>">
                                    <input type="hidden" name="enabled" value="<?= $requestedEnabled ? '0' : '1' ?>">
                                    <button class="btn <?= $requestedEnabled ? 'btn-danger' : 'btn-primary' ?>" type="submit"<?= !$requestedEnabled && (!$compatible || !$installed) ? ' disabled' : '' ?>>
                                        <?= icon($requestedEnabled ? 'close' : 'check') ?>
                                        <span><?= et($requestedEnabled ? 'extensions.disable' : 'extensions.enable') ?></span>
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
});
