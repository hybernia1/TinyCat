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
    <p class="help m-0"><?= Html::et('bots.account_create_help') ?></p>
</div>
<?php
$body = trim((string) ob_get_clean());
$footer = \TinyCat\Support\Components::render('ui/editor-actions', [
    'cancel_label' => Localization::t('common.cancel'),
    'primary' => new \TinyCat\Support\Markup('<button class="btn btn-primary" type="submit"><span>' . Html::et('common.create') . '</span></button>'),
]);

echo View::part('Support/modal', [
    'id' => 'bot-account-create-modal',
    'title' => Localization::t('bots.new_account'),
    'icon' => 'user-plus',
    'action' => $prepared['accounts_url'],
    'method' => 'POST',
    'target' => '#bot-accounts-list',
    'reset' => true,
    'closeOnSuccess' => true,
    'size' => 'workspace',
    'body' => $body,
    'footer' => $footer,
]);
