<?php
declare(strict_types=1);

use TinyCat\Support\Html;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$path = (string) ($path ?? '');
$target = (string) ($target ?? '');
$params = is_array($params ?? null) ? $params : [];
$selected = (int) ($selected ?? 25);
$historyPath = (string) ($history_path ?? $path);
$options = is_array($options ?? null) ? $options : [];
?>
<form class="admin-per-page-form" action="<?= Html::e($path) ?>" method="get" data-ajax-form data-ajax-target="<?= Html::e($target) ?>" data-history="<?= Html::e($historyPath) ?>">
    <?php foreach ($params as $key => $value): ?>
        <?php if ($key === 'per_page' || $value === '' || $value === null) {
            continue;
        } ?>
        <input type="hidden" name="<?= Html::e((string) $key) ?>" value="<?= Html::e((string) $value) ?>">
    <?php endforeach; ?>
    <label class="field-inline">
        <span class="label"><?= Html::et('common.per_page') ?></span>
        <select class="select select-sm" name="per_page" data-submit-on-change>
            <?php foreach ($options as $option): ?>
                <option value="<?= Html::e((string) $option) ?>"<?= $selected === $option ? ' selected' : '' ?>><?= Html::e((string) $option) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>
