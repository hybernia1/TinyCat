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
$create = (bool) ($create ?? false);
$id = (int) ($id ?? 0);
$source = is_array($source ?? null) ? $source : [];
$prepared = is_array($prepared ?? null) ? $prepared : [];

ob_start();
?>
<?php if (!$create): ?><input type="hidden" name="id" value="<?= Html::e($id) ?>"><?php endif; ?>
<?= \TinyCat\Bots\Views::sourceFields(['source' => $source + ['post_template' => $prepared['defaultSourceTemplate'], 'enabled' => $create], 'bots' => $bots]) ?>
<?php
$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . Html::icon('close') . ' <span>' . Html::et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . Html::icon('save') . ' <span>' . Html::et('common.save') . '</span></button>';

echo View::render('modals/layout', [
    'id' => $create ? 'bot-source-create-modal' : 'bot-source-edit-' . $id,
    'title' => Localization::t($create ? 'bots.new_source' : 'bots.edit_source'),
    'icon' => 'link',
    'action' => (string) ($action ?? $prepared['source_url']),
    'method' => $create ? 'POST' : 'PATCH',
    'ajax' => true,
    'target' => '#bots-list',
    'closeOnSuccess' => true,
    'size' => 'modal-panel-lg',
    'body' => $body,
    'footer' => $footer,
]);
