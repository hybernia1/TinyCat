<?php
declare(strict_types=1);

use TinyCat\Support\Html;
use TinyCat\Support\Localization;
use TinyCat\Support\View;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$prepared = is_array($prepared ?? null) ? $prepared : [];

ob_start();
?>
<div class="stack">
    <label class="field">
        <span class="label"><?= Html::et('common.username') ?></span>
        <input class="input" name="username" autocomplete="off" autocapitalize="none" spellcheck="false" pattern="<?= Html::e($prepared['username_pattern']) ?>" minlength="2" maxlength="32" required>
        <span class="help"><?= Html::e($prepared['username_hint']) ?></span>
    </label>
    <label class="field">
        <span class="label"><?= Html::et('common.status') ?></span>
        <select class="select" name="status">
            <?php foreach ($prepared['admin_user_statuses'] as $value => $label): ?>
                <option value="<?= Html::e($value) ?>"<?= $value === 'active' ? ' selected' : '' ?>><?= Html::e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <p class="help m-0"><?= Html::et('bots.account_create_help') ?></p>
</div>
<?php
$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . Html::icon('close') . ' <span>' . Html::et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . Html::icon('user-plus') . ' <span>' . Html::et('common.create') . '</span></button>';

echo View::render('modals/layout', [
    'id' => 'bot-account-create-modal',
    'title' => Localization::t('bots.new_account'),
    'icon' => 'user-plus',
    'action' => $prepared['accounts_url'],
    'method' => 'POST',
    'target' => '#bot-accounts-list',
    'reset' => true,
    'closeOnSuccess' => true,
    'body' => $body,
    'footer' => $footer,
]);
