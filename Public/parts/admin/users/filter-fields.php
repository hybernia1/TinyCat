<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$filters = is_array($filters ?? null) ? $filters : [];
$roles = is_array($roles ?? null) ? $roles : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$perPage = max(1, (int) ($per_page ?? 25));
?>
<div class="grid">
    <input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
    <input type="hidden" name="per_page" value="<?= e((string) $perPage) ?>">
    <input type="hidden" name="page" value="1">
    <label class="field">
        <span class="label"><?= et('common.role') ?></span>
        <select class="select" name="role">
            <?= tc_admin_options(['' => t('common.all')] + $roles, (string) ($filters['role'] ?? '')) ?>
        </select>
    </label>
    <label class="field">
        <span class="label"><?= et('common.status') ?></span>
        <select class="select" name="status">
            <?= tc_admin_options(['' => t('common.all')] + $statuses, (string) ($filters['status'] ?? '')) ?>
        </select>
    </label>
    <div class="grid sm:grid-2">
        <label class="field">
            <span class="label"><?= et('common.updated_from') ?></span>
            <input class="input" type="date" name="updated_from" value="<?= e((string) ($filters['updated_from'] ?? '')) ?>">
        </label>
        <label class="field">
            <span class="label"><?= et('common.updated_to') ?></span>
            <input class="input" type="date" name="updated_to" value="<?= e((string) ($filters['updated_to'] ?? '')) ?>">
        </label>
    </div>
</div>
