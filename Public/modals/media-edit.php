<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_media_api_url')) {
    http_response_code(404);
    return;
}

$media = (array) ($media ?? []);
$id = (int) ($media['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    return;
}

$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.save') . '</span></button>';

ob_start();
?>
<div class="grid sm:grid-2">
    <label class="field">
        <span class="label"><?= et('media.title_label') ?></span>
        <input class="input" name="title" value="<?= e((string) ($media['title'] ?? '')) ?>">
    </label>
    <label class="field">
        <span class="label"><?= et('media.alt') ?></span>
        <input class="input" name="alt" value="<?= e((string) ($media['alt'] ?? '')) ?>">
    </label>
</div>
<div class="table-wrap">
    <table class="table">
        <tbody>
            <tr><th><?= et('media.filename') ?></th><td><?= e((string) ($media['filename'] ?? '')) ?></td></tr>
            <tr><th><?= et('media.mime') ?></th><td><?= e((string) ($media['mime_type'] ?? '')) ?></td></tr>
            <tr><th><?= et('media.path') ?></th><td><code><?= e((string) ($media['path'] ?? '')) ?></code></td></tr>
        </tbody>
    </table>
</div>
<?php

echo render('modals/layout', [
    'id' => 'media-edit-' . $id,
    'title' => t('media.edit_title'),
    'icon' => 'edit',
    'action' => tc_admin_media_api_url('update', ['id' => $id]),
    'method' => 'PATCH',
    'target' => '#media-list',
    'closeOnSuccess' => true,
    'body' => trim((string) ob_get_clean()),
    'footer' => $footer,
]);
