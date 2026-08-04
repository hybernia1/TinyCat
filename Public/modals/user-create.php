<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

$body = part('admin/users/form-fields', [
    'user' => null,
    'roles' => tc_admin_roles(),
    'statuses' => admin_user_statuses(),
    'create' => true,
]);
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.create') . '</span></button>';

echo render('modals/layout', [
    'id' => 'user-create-modal',
    'title' => t('users.new_user'),
    'icon' => 'user-plus',
    'action' => tc_admin_users_api_url(),
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
