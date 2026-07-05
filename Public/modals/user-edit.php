<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_user_form_fields')) {
    http_response_code(404);
    return;
}

$user = (array) ($user ?? []);
$roles = (array) ($roles ?? tc_admin_roles());
$statuses = (array) ($statuses ?? tc_admin_statuses());
$id = (int) ($user['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    return;
}

$meta = '<div class="user-editor-meta">'
    . '<span>' . et('common.created') . ' <time datetime="' . e(tc_admin_datetime_iso((string) ($user['created_at'] ?? ''))) . '">' . e(tc_admin_datetime((string) ($user['created_at'] ?? ''))) . '</time></span>'
    . '<span>' . et('common.updated') . ' <time datetime="' . e(tc_admin_datetime_iso((string) ($user['updated_at'] ?? ''))) . '">' . e(tc_admin_datetime((string) ($user['updated_at'] ?? ''))) . '</time></span>'
    . '</div>';
$body = $meta . tc_admin_user_form_fields($user, $roles, $statuses, false);
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.save') . '</span></button>';

echo render('modals/layout', [
    'id' => 'user-edit-' . $id,
    'title' => t('users.edit_user', ['name' => (string) ($user['name'] ?? '')]),
    'icon' => 'edit',
    'action' => function_exists('tc_admin_users_api_url') ? tc_admin_users_api_url('update', ['id' => $id]) : '/admin/users?api=update&view=html&id=' . $id,
    'method' => 'PATCH',
    'target' => '#users-list',
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
