<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$path = (string) ($path ?? '');
$target = (string) ($target ?? '');
$params = is_array($params ?? null) ? $params : [];
$selected = (int) ($selected ?? admin_per_page());
$historyPath = (string) ($history_path ?? $path);
$params['page'] = 1;
?>
<form class="admin-per-page-form" action="<?= e($path) ?>" method="get" data-ajax-form data-ajax-target="<?= e($target) ?>" data-history="<?= e($historyPath) ?>">
    <input type="hidden" name="view" value="html">
    <?php foreach ($params as $key => $value): ?>
        <?php if ($key === 'per_page' || $value === '' || $value === null) {
            continue;
        } ?>
        <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
    <?php endforeach; ?>
    <label class="field-inline">
        <span class="label"><?= et('common.per_page') ?></span>
        <select class="select select-sm" name="per_page" data-submit-on-change>
            <?php foreach (admin_per_page_options() as $option): ?>
                <option value="<?= e((string) $option) ?>"<?= $selected === $option ? ' selected' : '' ?>><?= e((string) $option) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>
