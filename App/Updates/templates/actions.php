<?php
declare(strict_types=1);
/** @var array<mixed>|null $release */
/** @var bool $available */
/** @var bool $canInstall */
?>
        <div class="cluster gap-2">
            <?php if (is_array($release) && trim((string) ($release['notes'] ?? '')) !== ''): ?>
                <?= \TinyCat\Support\Components::render('ui/modal-trigger', ['class' => 'btn btn-secondary btn-sm', 'modal' => 'updates-release-notes', 'label' => \TinyCat\Support\Localization::t('updates.changelog'), 'icon' => 'file']) ?>
            <?php endif; ?>
            <form method="post" action="/api/updates/checks" data-ajax-form>
                <?= \TinyCat\Security\Csrf::field() ?>
                <input type="hidden" name="action" value="check">
                <button class="btn <?= $available ? 'btn-secondary' : 'btn-primary' ?> btn-sm" type="submit"><?= \TinyCat\Support\Html::icon('refresh') ?> <span><?= \TinyCat\Support\Html::et('updates.check') ?></span></button>
            </form>
            <?php if ($available): ?>
                <form method="post" action="/api/updates/installations" data-ajax-form data-confirm="<?= \TinyCat\Support\Html::et('updates.install_confirm', ['version' => (string) ($release['version'] ?? '')]) ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('updates.install_title') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('updates.install') ?>">
                    <?= \TinyCat\Security\Csrf::field() ?>
                    <input type="hidden" name="action" value="install">
                    <button class="btn btn-primary btn-sm" type="submit"<?= $canInstall ? '' : ' disabled' ?>><?= \TinyCat\Support\Html::icon('download') ?> <span><?= \TinyCat\Support\Html::et('updates.install') ?></span></button>
                </form>
            <?php endif; ?>
        </div>
