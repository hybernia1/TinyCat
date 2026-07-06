<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (!function_exists('tc_admin_users_filter_fields')) {
    http_response_code(404);
    return;
}

$filters = tc_admin_users_filters();
$roles = tc_admin_roles();
$statuses = tc_admin_statuses();
$body = tc_admin_users_filter_fields($filters, $roles, $statuses);
$clearParams = ['per_page' => admin_per_page(), 'page' => 1];
$footer = '<a class="btn btn-secondary" href="' . e(tc_admin_users_api_url('list', $clearParams, false)) . '" data-ajax data-ajax-target="#users-list" data-history="' . e(admin_list_url('/admin/users', $clearParams, false)) . '" data-modal-close>' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a>'
    . '<button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>';

echo render('modals/layout', [
    'id' => 'users-filter-modal',
    'title' => t('users.filter_title'),
    'icon' => 'filter',
    'action' => tc_admin_users_api_url('list'),
    'method' => 'GET',
    'target' => '#users-list',
    'closeOnSuccess' => true,
    'csrf' => false,
    'formAttributes' => [
        'data-history' => '/admin/users',
    ],
    'body' => $body,
    'footer' => $footer,
]);
