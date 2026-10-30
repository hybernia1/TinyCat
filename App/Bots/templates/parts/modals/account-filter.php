<?php
declare(strict_types=1);

use TinyCat\Support\Html;
use TinyCat\Support\View;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$filters = is_array($filters ?? null) ? $filters : [];
$prepared = is_array($prepared ?? null) ? $prepared : [];

ob_start();
?>
<input type="hidden" name="q" value="<?= Html::e((string) ($filters['q'] ?? '')) ?>">
<input type="hidden" name="per_page" value="<?= Html::e((string) (int) ($per_page ?? 10)) ?>">
<input type="hidden" name="page" value="1">
<label class="field">
    <span class="label"><?= Html::et('common.status') ?></span>
    <select class="select" name="status">
        <option value=""><?= Html::et('common.all') ?></option>
        <?php foreach ($prepared['admin_user_statuses'] as $value => $label): ?>
            <option value="<?= Html::e($value) ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= Html::e($label) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<?php
$body = trim((string) ob_get_clean());
$footer = '<a class="btn btn-ghost btn-sm" href="' . Html::e((string) ($clear_api_url ?? '/api/bots/accounts')) . '" data-ajax data-ajax-target="#bot-accounts-list" data-history="/admin/bots/accounts">' . Html::icon('close') . ' <span>' . Html::et('common.clear_filters') . '</span></a>';

echo View::part('Support/filter', [
    'id' => 'bot-accounts-filter-modal',
    'action' => $prepared['accounts_url'],
    'target' => '#bot-accounts-list',
    'formAttributes' => ['data-history' => '/admin/bots/accounts'],
    'body' => $body,
    'footer' => $footer,
]);
