<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = (array) ($user ?? []);
$authorId = (int) ($author_id ?? 0);
$action = (string) ($action ?? '');
$username = username_normalize((string) ($user['username'] ?? ''));
$config = Avatar::normalizeConfig($user['avatar_config'] ?? null);
$paint = (string) ($config['paint'] ?? Avatar::defaultPaint($username));
$palette = Avatar::paintPalette();
$size = Avatar::paintSize();
$empty = Avatar::paintEmpty();

if ($authorId < 1 || $action === '' || !username_valid($username)) {
    http_response_code(404);
    return;
}

ob_start();
?>
<input type="hidden" name="paint" value="<?= e($paint) ?>" data-avatar-paint-value>
<div class="avatar-paint" data-avatar-paint data-avatar-size="<?= e($size) ?>" data-avatar-empty="<?= e($empty) ?>" data-avatar-default="<?= e(Avatar::defaultPaint($username)) ?>" data-avatar-preview-url="/avatar/<?= e(rawurlencode($username)) ?>" data-avatar-random-url="/api/avatar/random">
    <aside class="avatar-paint-side">
        <div class="avatar-paint-preview">
            <img src="<?= e(Avatar::previewUrl($username, ['paint' => $paint])) ?>" alt="<?= et('account.avatar_preview') ?>" data-avatar-preview>
        </div>
        <div class="avatar-paint-tools" role="toolbar" aria-label="<?= et('account.avatar_tools') ?>">
            <button class="btn btn-secondary btn-sm btn-icon" type="button" data-avatar-undo title="<?= et('account.avatar_undo') ?>" aria-label="<?= et('account.avatar_undo') ?>" disabled><?= icon('undo') ?></button>
            <button class="btn btn-secondary btn-sm btn-icon" type="button" data-avatar-redo title="<?= et('account.avatar_redo') ?>" aria-label="<?= et('account.avatar_redo') ?>" disabled><?= icon('redo') ?></button>
            <button class="btn btn-secondary btn-sm" type="button" data-avatar-clear><?= icon('delete') ?> <span><?= et('account.avatar_clear') ?></span></button>
            <button class="btn btn-secondary btn-sm" type="button" data-avatar-template><?= icon('refresh') ?> <span><?= et('account.avatar_template') ?></span></button>
            <button class="btn btn-secondary btn-sm" type="button" data-avatar-random><?= icon('shuffle') ?> <span><?= et('account.avatar_random') ?></span></button>
        </div>
    </aside>
    <div class="avatar-paint-main">
        <div class="avatar-paint-palette" role="toolbar" aria-label="<?= et('account.avatar_palette') ?>">
            <button class="avatar-paint-swatch is-eraser" type="button" data-avatar-color="<?= e($empty) ?>" aria-label="<?= et('account.avatar_eraser') ?>">
                <?= icon('close') ?>
            </button>
            <?php foreach ($palette as $index => $color): ?>
                <?php $token = dechex((int) $index); ?>
                <button class="avatar-paint-swatch" type="button" data-avatar-color="<?= e($token) ?>" style="--swatch: <?= e((string) $color) ?>" aria-label="<?= et('account.avatar_color', ['number' => $index + 1]) ?>"></button>
            <?php endforeach; ?>
        </div>
        <div class="avatar-paint-board" data-avatar-board style="--avatar-paint-size: <?= e($size) ?>" aria-label="<?= et('account.avatar_canvas') ?>">
            <?php for ($i = 0, $total = $size * $size; $i < $total; $i++): ?>
                <?php
                $token = $paint[$i] ?? $empty;
                $paletteIndex = $token !== $empty ? hexdec($token) : -1;
                $color = $palette[$paletteIndex] ?? '';
                ?>
                <button class="avatar-paint-cell<?= $token === $empty ? ' is-empty' : '' ?>" type="button" data-avatar-pixel data-index="<?= e($i) ?>" data-value="<?= e($token) ?>"<?= $color !== '' ? ' style="--pixel: ' . e((string) $color) . '"' : '' ?> aria-label="<?= et('account.avatar_pixel', ['number' => $i + 1]) ?>"></button>
            <?php endfor; ?>
        </div>
    </div>
</div>
<?php

$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('account.save_avatar') . '</span></button>';

echo render('modals/layout', [
    'id' => author_avatar_edit_modal_id($authorId),
    'title' => t('account.avatar_paint'),
    'icon' => 'edit',
    'action' => $action,
    'ajax' => true,
    'size' => 'modal-panel-lg avatar-edit-modal-panel',
    'formAttributes' => [
        'data-avatar-form-shell' => 'true',
        'data-confirm-unsaved' => 'true',
        'data-confirm-unsaved-title' => t('common.unsaved_title'),
        'data-confirm-unsaved-message' => t('common.unsaved_message'),
        'data-confirm-unsaved-ok' => t('common.leave'),
        'data-confirm-unsaved-cancel' => t('common.stay'),
    ],
    'body' => $body,
    'footer' => $footer,
]);
