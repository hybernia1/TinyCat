<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$filters = is_array($filters ?? null) ? $filters : [];

ob_start();
?>
<input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
<input type="hidden" name="per_page" value="<?= e((string) (int) ($per_page ?? 10)) ?>">
<input type="hidden" name="page" value="1">
<label class="field">
    <span class="label"><?= et('common.status') ?></span>
    <select class="select" name="status">
        <option value=""><?= et('common.all') ?></option>
        <?php foreach (admin_user_statuses() as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<?php
$body = trim((string) ob_get_clean());
$footer = '<a class="btn btn-secondary" href="' . e((string) ($clear_api_url ?? '/api/admin/bot-accounts')) . '" data-ajax data-ajax-target="#bot-accounts-list" data-history="/admin/bots/accounts" data-modal-close>' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a>'
    . '<button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>';

echo render('modals/layout', [
    'id' => 'bot-accounts-filter-modal',
    'title' => t('bots.accounts_filter_title'),
    'icon' => 'filter',
    'action' => BotAdmin::accountsApiUrl(),
    'method' => 'GET',
    'target' => '#bot-accounts-list',
    'closeOnSuccess' => true,
    'csrf' => false,
    'formAttributes' => ['data-history' => '/admin/bots/accounts'],
    'body' => $body,
    'footer' => $footer,
]);
