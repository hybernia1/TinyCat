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

$body = '<label class="dropzone" data-dropzone>'
    . '<input class="sr-only" type="file" name="file" required>'
    . '<span class="dropzone-content"><span class="dropzone-icon">' . icon('upload') . '</span><strong>' . et('media.drop_file') . '</strong></span>'
    . '<span class="dropzone-files" data-dropzone-files></span>'
    . '</label>'
    . '<div class="grid sm:grid-2">'
    . '<label class="field"><span class="label">' . et('media.title_label') . '</span><input class="input" name="title"></label>'
    . '<label class="field"><span class="label">' . et('media.alt') . '</span><input class="input" name="alt"></label>'
    . '</div>';
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('upload') . ' <span>' . et('media.upload') . '</span></button>';

echo render('modals/layout', [
    'id' => 'media-upload-modal',
    'title' => t('media.upload'),
    'icon' => 'upload',
    'action' => tc_admin_media_api_url('upload'),
    'target' => '#media-list',
    'multipart' => true,
    'reset' => true,
    'closeOnSuccess' => true,
    'body' => $body,
    'footer' => $footer,
]);
