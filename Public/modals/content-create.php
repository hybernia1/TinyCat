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

$body = tc_admin_content_form_fields(null);
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.create') . '</span></button>';

echo render('modals/layout', [
    'id' => 'content-create-modal',
    'title' => t('content.new_content'),
    'icon' => 'plus',
    'action' => function_exists('tc_admin_content_api_url') ? tc_admin_content_api_url('create') : '/admin/content?api=create&view=html',
    'target' => '#content-list',
    'reset' => true,
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
