<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$name = (string) ($name ?? '');
$url = trim((string) ($url ?? ''));
$settingKey = trim(str_replace(['settings[', ']'], '', $name));
?>
<input type="hidden" name="<?= e($name) ?>" value="<?= e($url) ?>">
<div class="content-image-preview settings-image-preview"<?= $url === '' ? ' data-empty="true"' : '' ?>>
    <?php if ($url !== ''): ?>
        <img src="<?= e($url) ?>" alt="" loading="lazy">
    <?php else: ?>
        <span class="content-image-preview-empty"><?= icon('image') ?> <?= et('settings.image_empty') ?></span>
    <?php endif; ?>
</div>
<div class="content-image-actions">
    <label class="btn btn-secondary btn-sm">
        <?= icon('upload') ?> <span><?= et('settings.image_upload') ?></span>
        <input class="sr-only" type="file" name="settings_files[<?= e($settingKey) ?>]" accept="image/jpeg,image/png,image/gif,image/webp">
    </label>
    <?php if ($url !== ''): ?>
        <label class="check-line mb-0">
            <input type="checkbox" name="settings_remove[<?= e($settingKey) ?>]" value="1">
            <span><?= et('settings.image_remove') ?></span>
        </label>
    <?php endif; ?>
</div>
