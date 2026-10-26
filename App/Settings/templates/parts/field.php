<?php
declare(strict_types=1);

use TinyCat\Support\Html;
use TinyCat\Support\Localization;
use TinyCat\Support\View;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$classes = is_array($classes ?? null) ? $classes : [];
$displayValue = $displayValue ?? '';
$field = is_array($field ?? null) ? $field : [];
$name = (string) ($name ?? '');
$tag = (string) ($tag ?? '');
$type = (string) ($type ?? '');
$value = $value ?? '';
$prepared = is_array($prepared ?? null) ? $prepared : [];
?>
<<?= $tag ?> class="<?= Html::e(implode(' ', $classes)) ?>">
    <span class="label"><?= Html::e((string) ($field['label'] ?? '')) ?></span>
    <?php if ($type === 'bool'): ?>
        <span class="check-line">
            <input type="hidden" name="<?= Html::e($name) ?>" value="0">
            <input type="checkbox" name="<?= Html::e($name) ?>" value="1"<?= (bool) $value ? ' checked' : '' ?>>
            <span><?= Html::et('settings.enabled') ?></span>
        </span>
    <?php elseif ($type === 'email_templates'): ?>
        <?php $states = is_array($value) ? $value : []; ?>
        <input type="hidden" name="<?= Html::e($name . '[_present]') ?>" value="1">
        <div class="stack gap-2">
            <?php foreach ($prepared['email_template_keys'] as $templateKey): ?>
                <label class="check-line">
                    <input type="checkbox" name="<?= Html::e($name . '[' . $templateKey . ']') ?>" value="1"<?= !empty($states[$templateKey]) ? ' checked' : '' ?>>
                    <span><?= Html::e(ucwords(str_replace('_', ' ', $templateKey))) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php elseif ($type === 'email_smtp'): ?>
        <?php $smtp = is_array($value) ? $value : []; ?>
        <div class="settings-grid">
            <label class="field"><span class="label"><?= Html::et('settings.fields.smtp_host') ?></span><input class="input" name="<?= Html::e($name . '[host]') ?>" value="<?= Html::e((string) ($smtp['host'] ?? '')) ?>" maxlength="190"></label>
            <label class="field"><span class="label"><?= Html::et('settings.fields.smtp_port') ?></span><input class="input" type="number" name="<?= Html::e($name . '[port]') ?>" value="<?= Html::e((int) ($smtp['port'] ?? 587)) ?>" min="1" max="65535" required></label>
            <label class="field"><span class="label"><?= Html::et('settings.fields.smtp_username') ?></span><input class="input" name="<?= Html::e($name . '[username]') ?>" value="<?= Html::e((string) ($smtp['username'] ?? '')) ?>" maxlength="190"></label>
            <label class="field"><span class="label"><?= Html::et('settings.fields.smtp_password') ?></span><input class="input" type="password" name="<?= Html::e($name . '[password]') ?>" value="" maxlength="190" autocomplete="new-password"></label>
            <label class="field"><span class="label"><?= Html::et('settings.fields.smtp_encryption') ?></span><input class="input" name="<?= Html::e($name . '[encryption]') ?>" value="<?= Html::e((string) ($smtp['encryption'] ?? 'tls')) ?>" maxlength="20"></label>
            <label class="field"><span class="label"><?= Html::et('settings.fields.email_from') ?></span><input class="input" type="email" name="<?= Html::e($name . '[from_address]') ?>" value="<?= Html::e((string) ($smtp['from_address'] ?? '')) ?>" maxlength="190"></label>
            <label class="field"><span class="label"><?= Html::et('settings.fields.email_from_name') ?></span><input class="input" name="<?= Html::e($name . '[from_name]') ?>" value="<?= Html::e((string) ($smtp['from_name'] ?? 'TinyCat')) ?>" maxlength="120"></label>
        </div>
    <?php elseif ($type === 'language'): ?>
        <select class="select" name="<?= Html::e($name) ?>"><?= $prepared['options_html'] ?></select>
    <?php elseif ($type === 'select'): ?>
        <select class="select" name="<?= Html::e($name) ?>" required>
            <?php foreach ((array) ($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                <option value="<?= Html::e((string) $optionValue) ?>"<?= (string) $value === (string) $optionValue ? ' selected' : '' ?>><?= Html::e((string) $optionLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($type === 'timezone'): ?>
        <select class="select" name="<?= Html::e($name) ?>" required><?= $prepared['options_html'] ?></select>
    <?php elseif ($type === 'date_format'): ?>
        <select class="select" name="<?= Html::e($name) ?>" required><?= $prepared['options_html'] ?></select>
    <?php elseif ($type === 'time_format'): ?>
        <select class="select" name="<?= Html::e($name) ?>" required><?= $prepared['options_html'] ?></select>
    <?php elseif ($type === 'datetime_format'): ?>
        <select class="select" name="<?= Html::e($name) ?>" required><?= $prepared['options_html'] ?></select>
    <?php elseif ($type === 'int'): ?>
        <input class="input" type="number" name="<?= Html::e($name) ?>" value="<?= Html::e((int) $value) ?>" min="<?= Html::e((int) ($field['min'] ?? 0)) ?>" max="<?= Html::e((int) ($field['max'] ?? PHP_INT_MAX)) ?>" required>
    <?php elseif ($type === 'site_image'): ?>
        <?= View::part('Settings/site-image', ['name' => $name, 'url' => (string) $value]) ?>
    <?php elseif ($type === 'password'): ?>
        <input class="input" type="password" name="<?= Html::e($name) ?>" value="<?= Html::e((string) $displayValue) ?>" maxlength="<?= Html::e((int) ($field['max'] ?? 190)) ?>" autocomplete="new-password">
    <?php elseif ($type === 'email'): ?>
        <input class="input" type="email" name="<?= Html::e($name) ?>" value="<?= Html::e((string) $value) ?>" maxlength="<?= Html::e((int) ($field['max'] ?? 190)) ?>">
    <?php elseif ($type === 'optional_text'): ?>
        <input class="input" name="<?= Html::e($name) ?>" value="<?= Html::e((string) $value) ?>" maxlength="<?= Html::e((int) ($field['max'] ?? 190)) ?>">
    <?php elseif ($type === 'textarea'): ?>
        <textarea class="textarea" name="<?= Html::e($name) ?>" rows="8" maxlength="<?= Html::e((int) ($field['max'] ?? 5000)) ?>" placeholder="<?= Html::e((string) ($field['placeholder'] ?? Localization::t('settings.footer_placeholder'))) ?>"><?= Html::e((string) $value) ?></textarea>
    <?php else: ?>
        <input class="input" name="<?= Html::e($name) ?>" value="<?= Html::e((string) $value) ?>" maxlength="<?= Html::e((int) ($field['max'] ?? 190)) ?>" required>
    <?php endif; ?>
    <?php if (!empty($field['help'])): ?><span class="help"><?= Html::e((string) $field['help']) ?></span><?php endif; ?>
</<?= $tag ?>>
