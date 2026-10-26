<?php
declare(strict_types=1);
/** @var array<mixed> $settingsSections */
$sections = $settingsSections;
$active = array_key_first($sections) ?: 'general';
?>
<section class="card" data-tabs>
    <div class="card-header">
        <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('settings') ?> <?= \TinyCat\Support\Html::et('settings.title') ?></h2>
    </div>

    <div class="tabs px-4" role="tablist" aria-label="<?= \TinyCat\Support\Html::et('settings.title') ?>">
        <?php foreach ($sections as $key => $section): ?>
            <?php $selected = $key === $active; ?>
            <button class="tab" type="button" id="settings-tab-<?= \TinyCat\Support\Html::e($key) ?>" role="tab" aria-controls="settings-panel-<?= \TinyCat\Support\Html::e($key) ?>" aria-selected="<?= $selected ? 'true' : 'false' ?>" data-tab="<?= \TinyCat\Support\Html::e($key) ?>">
                <?= \TinyCat\Support\Html::icon((string) ($section['icon'] ?? 'settings')) ?> <?= \TinyCat\Support\Html::e((string) $section['label']) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <form method="post" action="/api/settings" data-api-method="PATCH" enctype="multipart/form-data" data-ajax-form>
        <?= \TinyCat\Security\Csrf::field() ?>
        <div class="card-body stack">
            <?php foreach ($sections as $key => $section): ?>
                <?php $selected = $key === $active; ?>
                <div class="tab-panel stack" id="settings-panel-<?= \TinyCat\Support\Html::e($key) ?>" role="tabpanel" aria-labelledby="settings-tab-<?= \TinyCat\Support\Html::e($key) ?>" data-tab-panel="<?= \TinyCat\Support\Html::e($key) ?>"<?= $selected ? '' : ' hidden' ?>>
                    <div class="settings-grid">
                        <?php foreach ((array) $section['fields'] as $field): ?>
                            <?= \TinyCat\Settings\Views::field(['field' => $field]) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card-footer cluster justify-end">
            <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('save') ?> <span><?= \TinyCat\Support\Html::et('common.save') ?></span></button>
        </div>
    </form>
</section>
