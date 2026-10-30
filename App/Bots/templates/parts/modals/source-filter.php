<?php
declare(strict_types=1);

use TinyCat\Support\Html;
use TinyCat\Support\View;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$bots = is_array($bots ?? null) ? $bots : [];
$filterBotId = max(0, (int) ($filter_bot_id ?? 0));

ob_start();
?>
<?php foreach ((array) ($filters ?? []) as $name => $value): ?>
    <?php if ($name !== 'bot'): ?><input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>"><?php endif; ?>
<?php endforeach; ?>
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
$footer = '<a class="btn btn-ghost btn-sm" href="/api/bots/sources" data-ajax data-ajax-target="#bots-list" data-history="/admin/bots/list">' . Html::icon('close') . ' <span>' . Html::et('common.clear_filters') . '</span></a>';

echo View::part('Support/filter', [
    'id' => 'bots-filter-modal',
    'action' => '/api/bots/sources',
    'target' => '#bots-list',
    'formAttributes' => ['data-history' => '/admin/bots/list'],
    'body' => $body,
    'footer' => $footer,
]);
