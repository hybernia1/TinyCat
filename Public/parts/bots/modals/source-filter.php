<?php
declare(strict_types=1);

use TinyCat\Support\Html;
use TinyCat\Support\Localization;
use TinyCat\Support\View;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$bots = is_array($bots ?? null) ? $bots : [];
$filterBotId = max(0, (int) ($filter_bot_id ?? 0));

ob_start();
?>
<label class="field">
    <span class="label"><?= Html::et('bots.filter_by_bot') ?></span>
    <select class="select" name="bot">
        <option value="0"><?= Html::et('bots.all_bots') ?></option>
        <?php foreach ($bots as $bot): ?>
            <option value="<?= Html::e((int) $bot['id']) ?>"<?= $filterBotId === (int) $bot['id'] ? ' selected' : '' ?>>@<?= Html::e((string) $bot['username']) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<?php
$body = trim((string) ob_get_clean());
$footer = '<a class="btn btn-secondary" href="/api/bots/sources" data-ajax data-ajax-target="#bots-list" data-history="/admin/bots/list" data-modal-close>' . Html::icon('close') . ' <span>' . Html::et('common.clear_filters') . '</span></a>'
    . '<button class="btn btn-primary" type="submit">' . Html::icon('filter') . ' <span>' . Html::et('common.apply_filters') . '</span></button>';

echo View::render('modals/layout', [
    'id' => 'bots-filter-modal',
    'title' => Localization::t('bots.filter_by_bot'),
    'icon' => 'filter',
    'action' => '/api/bots/sources',
    'method' => 'GET',
    'ajax' => true,
    'target' => '#bots-list',
    'closeOnSuccess' => true,
    'formAttributes' => ['data-history' => '/admin/bots/list'],
    'csrf' => false,
    'body' => $body,
    'footer' => $footer,
]);
