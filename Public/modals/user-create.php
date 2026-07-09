<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (!function_exists('tc_admin_user_form_fields')) {
    http_response_code(404);
    return;
}

$body = tc_admin_user_form_fields(null, tc_admin_roles(), tc_admin_statuses(), true);
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.create') . '</span></button>';

echo render('modals/layout', [
    'id' => 'user-create-modal',
    'title' => t('users.new_user'),
    'icon' => 'user-plus',
    'action' => tc_admin_users_api_url('create'),
    'target' => '#users-list',
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
    'size' => 'modal-panel-full user-modal-panel',
    'body' => $body,
    'footer' => $footer,
]);
