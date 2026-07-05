<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_content_form_fields')) {
    http_response_code(404);
    return;
}

$item = (array) ($item ?? []);
$id = (int) ($item['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    return;
}

$meta = '<div class="content-editor-meta">'
    . '<span>' . et('common.created') . ' <time datetime="' . e(tc_admin_content_datetime_iso((string) ($item['created_at'] ?? ''))) . '">' . e(tc_admin_content_datetime((string) ($item['created_at'] ?? ''))) . '</time></span>'
    . '<span>' . et('common.updated') . ' <time datetime="' . e(tc_admin_content_datetime_iso((string) ($item['updated_at'] ?? ''))) . '">' . e(tc_admin_content_datetime((string) ($item['updated_at'] ?? ''))) . '</time></span>'
    . '</div>';
$body = $meta . tc_admin_content_form_fields($item);
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.save') . '</span></button>';

echo render('modals/layout', [
    'id' => 'content-edit-' . $id,
    'title' => t('content.edit_content', ['title' => (string) ($item['title'] ?? '')]),
    'icon' => 'edit',
    'action' => function_exists('tc_admin_content_api_url') ? tc_admin_content_api_url('update', ['id' => $id]) : '/admin/content?api=update&view=html&id=' . $id,
    'method' => 'PATCH',
    'target' => '#content-list',
    'closeOnSuccess' => true,
    'formAttributes' => [
        'data-confirm-unsaved' => 'true',
        'data-confirm-unsaved-title' => t('common.unsaved_title'),
        'data-confirm-unsaved-message' => t('common.unsaved_message'),
        'data-confirm-unsaved-ok' => t('common.leave'),
        'data-confirm-unsaved-cancel' => t('common.stay'),
    ],
    'modalClass' => 'modal-fullscreen',
    'size' => 'modal-panel-full content-modal-panel',
    'body' => $body,
    'footer' => $footer,
]);
