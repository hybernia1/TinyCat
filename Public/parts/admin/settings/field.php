<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$field = is_array($field ?? null) ? $field : [];
$key = (string) ($field['key'] ?? '');
$type = (string) ($field['type'] ?? 'text');
$value = config($key, $field['default'] ?? '');
$displayValue = $type === 'password' ? '' : $value;
$name = 'settings[' . $key . ']';
$tag = $type === 'site_image' ? 'div' : 'label';
$classes = ['field', 'settings-field'];

if ($type === 'site_image') {
    $classes[] = 'settings-image-field';
}
if ($type === 'textarea') {
    $classes[] = 'settings-editor-field';
}
if (!empty($field['span'])) {
    $classes[] = 'settings-field-span';
}
if (!empty($field['compact'])) {
    $classes[] = 'settings-field-compact';
}
?>
<<?= $tag ?> class="<?= e(implode(' ', $classes)) ?>">
    <span class="label"><?= e((string) ($field['label'] ?? '')) ?></span>
    <?php if ($type === 'bool'): ?>
        <span class="check-line">
            <input type="checkbox" name="<?= e($name) ?>" value="1"<?= (bool) $value ? ' checked' : '' ?>>
            <span><?= et('settings.enabled') ?></span>
        </span>
    <?php elseif ($type === 'language'): ?>
        <select class="select" name="<?= e($name) ?>"><?= language_options((string) $value) ?></select>
    <?php elseif ($type === 'timezone'): ?>
        <select class="select" name="<?= e($name) ?>" required><?= timezone_options((string) $value) ?></select>
    <?php elseif ($type === 'date_format'): ?>
        <select class="select" name="<?= e($name) ?>" required><?= datetime_format_preset_options('date', (string) $value) ?></select>
    <?php elseif ($type === 'time_format'): ?>
        <select class="select" name="<?= e($name) ?>" required><?= datetime_format_preset_options('time', (string) $value) ?></select>
    <?php elseif ($type === 'datetime_format'): ?>
        <select class="select" name="<?= e($name) ?>" required><?= datetime_format_preset_options('datetime', (string) $value) ?></select>
    <?php elseif ($type === 'int'): ?>
        <input class="input" type="number" name="<?= e($name) ?>" value="<?= e((int) $value) ?>" min="<?= e((int) ($field['min'] ?? 0)) ?>" max="<?= e((int) ($field['max'] ?? PHP_INT_MAX)) ?>" required>
    <?php elseif ($type === 'mb'): ?>
        <input class="input" type="number" name="<?= e($name) ?>" value="<?= e(tc_admin_settings_bytes_to_mb((int) $value)) ?>" min="<?= e((float) ($field['min'] ?? 0)) ?>" max="<?= e((float) ($field['max'] ?? 1024)) ?>" step="0.1" required>
    <?php elseif ($type === 'site_image'): ?>
        <?= part('admin/settings/site-image', ['name' => $name, 'url' => (string) $value]) ?>
    <?php elseif ($type === 'password'): ?>
        <input class="input" type="password" name="<?= e($name) ?>" value="<?= e((string) $displayValue) ?>" maxlength="<?= e((int) ($field['max'] ?? 190)) ?>" autocomplete="new-password">
    <?php elseif ($type === 'email'): ?>
        <input class="input" type="email" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" maxlength="<?= e((int) ($field['max'] ?? 190)) ?>">
    <?php elseif ($type === 'optional_text'): ?>
        <input class="input" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" maxlength="<?= e((int) ($field['max'] ?? 190)) ?>">
    <?php elseif ($type === 'textarea'): ?>
        <textarea class="textarea" name="<?= e($name) ?>" rows="8" maxlength="<?= e((int) ($field['max'] ?? 5000)) ?>" placeholder="<?= e((string) ($field['placeholder'] ?? t('settings.footer_placeholder'))) ?>"><?= e((string) $value) ?></textarea>
    <?php else: ?>
        <input class="input" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" maxlength="<?= e((int) ($field['max'] ?? 190)) ?>" required>
    <?php endif; ?>
    <?php if (!empty($field['help'])): ?><span class="help"><?= e((string) $field['help']) ?></span><?php endif; ?>
</<?= $tag ?>>
