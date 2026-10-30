<?php
declare(strict_types=1);
/** @var array<mixed> $settingsSections */
use TinyCat\Support\Html;
use TinyCat\Settings\Views;
?>
<template data-settings-editor>
    <form method="post" action="/api/settings" data-api-method="PATCH" enctype="multipart/form-data" data-ajax-form data-confirm-unsaved="true">
        <?= \TinyCat\Security\Csrf::field() ?>
        <div class="stack stack-gap-16" data-editor-sections>
            <?php foreach ($settingsSections as $key => $section): ?>
                <div class="grid sm:grid-2" data-editor-section="<?= Html::e($key) ?>" data-editor-label="<?= Html::e((string) $section['label']) ?>" data-editor-icon="<?= Html::e((string) ($section['icon'] ?? 'settings')) ?>">
                    <?php foreach ((array) $section['fields'] as $field): ?>
                        <?= Views::field(['field' => $field]) ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" type="submit"><?= Html::icon('save') ?> <span><?= Html::et('common.save') ?></span></button>
    </form>
</template>
