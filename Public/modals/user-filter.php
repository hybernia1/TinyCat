<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

$filters = tc_admin_users_filters();
$roles = tc_admin_roles();
$statuses = admin_user_statuses();
$perPage = max(1, (int) ($per_page ?? admin_per_page()));
$body = part('admin/users/filter-fields', [
    'filters' => $filters,
    'roles' => $roles,
    'statuses' => $statuses,
    'per_page' => $perPage,
]);
$clearParams = ['per_page' => $perPage, 'page' => 1];
$footer = '<a class="btn btn-secondary" href="' . e(tc_admin_users_api_url($clearParams, false)) . '" data-ajax data-ajax-target="#users-list" data-history="' . e(admin_list_url('/admin/users', $clearParams, false)) . '" data-modal-close>' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a>'
    . '<button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>';

echo render('modals/layout', [
    'id' => 'users-filter-modal',
    'title' => t('users.filter_title'),
    'icon' => 'filter',
    'action' => tc_admin_users_api_url(),
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
