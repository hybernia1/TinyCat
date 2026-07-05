<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_settings_file_library_html')) {
    http_response_code(404);
    return;
}

$filePickerUpload = static function (string $type): string {
    $type = tc_admin_settings_file_picker_type($type);
    $isImage = $type === 'image';
    $libraryId = 'settings-file-picker-' . $type . '-library';

    ob_start();
    ?>
    <section class="file-picker-screen" data-file-picker-screen="<?= e($type) ?>">
        <form class="file-picker-upload" action="/admin/settings?api=file-upload&view=html&type=<?= e($type) ?>" method="post" enctype="multipart/form-data" data-ajax-form data-ajax-target="#<?= e($libraryId) ?>" data-reset="true" data-file-upload-form>
            <?= csrf_field() ?>
            <label class="dropzone file-picker-dropzone" data-dropzone>
                <input class="sr-only" type="file" name="file" accept="<?= e(tc_admin_settings_file_accept($type)) ?>" required>
                <span class="dropzone-content">
                    <span class="dropzone-icon"><?= icon('upload') ?></span>
                    <strong><?= et($isImage ? 'content.file_upload_image' : 'content.file_upload_file') ?></strong>
                </span>
                <span class="dropzone-files" data-dropzone-files></span>
            </label>
            <div class="file-picker-upload-fields">
                <label class="field">
                    <span class="label"><?= et($isImage ? 'content.file_image_title' : 'content.file_title') ?></span>
                    <input class="input" name="title" placeholder="<?= et('content.file_title_placeholder') ?>">
                </label>
                <button class="btn btn-primary" type="submit"><?= icon('upload') ?> <span><?= et($isImage ? 'content.file_upload_image_button' : 'content.file_upload_file_button') ?></span></button>
            </div>
        </form>

        <div class="file-picker-toolbar">
            <label class="field file-picker-search">
                <span class="label"><?= et('content.file_search') ?></span>
                <span class="input-icon">
                    <?= icon('search') ?>
                    <input class="input" type="search" data-file-search placeholder="<?= et('content.file_search_placeholder') ?>">
                </span>
            </label>
            <button class="btn btn-secondary" type="button" data-file-clear>
                <?= icon('unlink') ?> <span><?= et('content.file_clear_selection') ?></span>
            </button>
        </div>

        <div class="file-picker-library" id="<?= e($libraryId) ?>" data-file-library>
            <?= tc_admin_settings_file_library_html($type) ?>
        </div>
    </section>
    <?php

    return trim((string) ob_get_clean());
};

ob_start();
?>
<div class="file-picker" data-file-picker>
    <?= $filePickerUpload('image') ?>
    <?= $filePickerUpload('file') ?>
</div>
<?php

$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.close') . '</span></button>';

echo render('modals/layout', [
    'id' => 'settings-file-picker',
    'title' => t('content.file_picker_title'),
    'icon' => 'image',
    'size' => 'modal-panel-xl',
    'body' => trim((string) ob_get_clean()),
    'footer' => $footer,
    'panelAttributes' => [
        'data-file-picker-panel' => true,
    ],
]);
